<?php
/**
 * Unit: Shipment_Freshness — the fingerprint is snapshotted at the request, promoted at the export and compared later (#947).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Admin\Orders\Shipment_Freshness;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Order\Shipment_Fingerprint;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Shipment_Freshness
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::record_shipment_fingerprint
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::snapshot_shipment_fingerprint
 */
final class ShipmentFreshnessTest extends TestCase {

	/** @var array<string,mixed> the post meta of order 123 */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		Orders_Registry::instance()->reset_for_tests();

		$this->meta = [];

		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, int $flags = 0 ) {
				return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double of wp_json_encode().
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $overrides getter name => value
	 * @return \Mockery\MockInterface&\WC_Order
	 */
	private function order( array $overrides = [] ) {
		$values = array_merge(
			[
				'get_id'                  => 123,
				'get_total'               => '1300.00',
				'get_shipping_first_name' => 'Иван',
				'get_shipping_last_name'  => 'Иванов',
				'get_billing_first_name'  => 'Иван',
				'get_billing_last_name'   => 'Иванов',
				'get_shipping_phone'      => '+79991234567',
				'get_billing_phone'       => '+79991234567',
				'get_shipping_country'    => 'RU',
				'get_shipping_state'      => '',
				'get_shipping_city'       => 'Москва',
				'get_shipping_postcode'   => '101000',
				'get_shipping_address_1'  => 'ул. Тверская, 1',
				'get_shipping_address_2'  => '',
				'get_billing_country'     => 'RU',
				'get_billing_state'       => '',
				'get_billing_city'        => 'Москва',
				'get_billing_postcode'    => '101000',
				'get_billing_address_1'   => 'ул. Тверская, 1',
				'get_billing_address_2'   => '',
				'get_shipping_methods'    => [],
				'get_items'               => [],
			],
			$overrides
		);

		$order = Mockery::mock( '\WC_Order' );

		foreach ( $values as $getter => $value ) {
			$order->shouldReceive( $getter )->andReturn( $value );
		}

		return $order;
	}

	private function provider( ?string $pickup_key = '_wc_cdek_pickup' ): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_wc_cdek_marker',
			[ 'flat_rate' ],
			[
				'carrier_order_id_meta_key' => '_wc_cdek_order_id',
				'pickup_point_meta_key'     => $pickup_key,
			]
		);
	}

	public function test_a_request_snapshots_the_order_with_its_pickup_point_as_the_pending_fingerprint(): void {
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( $this->provider() );

		$this->meta['_wc_cdek_pickup'] = [ 'id' => 'PVZ-1', 'address' => 'Москва, ул. Арбат, 1' ];

		$order = $this->order();
		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_woodev_shipment_fingerprint_pending', Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' ) );
		$order->shouldReceive( 'save_meta_data' )->once();

		( new Shipment_Freshness( $registry ) )->snapshot( $order );
	}

	public function test_a_request_for_an_order_no_carrier_claims_snapshots_nothing(): void {
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( null );

		$order = $this->order();
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save_meta_data' );

		( new Shipment_Freshness( $registry ) )->snapshot( $order );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_export_promotes_the_pending_snapshot_and_never_reads_the_order_as_it_is_now(): void {
		// Nothing is resolved and no getter is called: the stored value is what the request carried.
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldNotReceive( 'resolve_provider_for_order' );

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_fingerprint_pending' )->andReturn( 'v1:' . str_repeat( 'a', 64 ) );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_woodev_shipment_fingerprint', 'v1:' . str_repeat( 'a', 64 ) );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_woodev_shipment_fingerprint_pending' );
		$order->shouldReceive( 'save_meta_data' )->once();

		( new Shipment_Freshness( $registry ) )->record( $order );
	}

	public function test_the_registrys_listeners_snapshot_and_promote_through_the_same_path(): void {
		$registry = Orders_Registry::instance();
		$plugin   = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_checkout_handler' )->andReturn( null );
		$registry->register_provider( $this->provider( null ), $plugin );

		$this->meta['_wc_cdek_marker'] = 'yes';

		$order = $this->order();
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_woodev_shipment_fingerprint_pending', Shipment_Fingerprint::for_order( $this->order() ) );
		$order->shouldReceive( 'save_meta_data' )->once();

		$registry->snapshot_shipment_fingerprint( $order );

		$promoted = Mockery::mock( '\WC_Order' );
		$promoted->shouldReceive( 'get_meta' )->with( '_woodev_shipment_fingerprint_pending' )->andReturn( 'v1:' . str_repeat( 'b', 64 ) );
		$promoted->shouldReceive( 'update_meta_data' )->once()->with( '_woodev_shipment_fingerprint', 'v1:' . str_repeat( 'b', 64 ) );
		$promoted->shouldReceive( 'delete_meta_data' )->once()->with( '_woodev_shipment_fingerprint_pending' );
		$promoted->shouldReceive( 'save_meta_data' )->once();

		$registry->record_shipment_fingerprint( $promoted );
	}

	public function test_an_order_unchanged_since_the_export_is_not_outdated(): void {
		$registry = Mockery::mock( Orders_Registry::class );
		$provider = $this->provider();

		$this->meta['_wc_cdek_pickup']            = [ 'id' => 'PVZ-1' ];
		$this->meta[ Shipment_Fingerprint::META ] = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$this->assertFalse( ( new Shipment_Freshness( $registry ) )->is_outdated( $this->order(), $provider ) );
	}

	public function test_a_change_of_the_address_or_of_the_pickup_point_after_the_export_is_outdated(): void {
		$registry = Mockery::mock( Orders_Registry::class );
		$provider = $this->provider();

		$this->meta['_wc_cdek_pickup']            = [ 'id' => 'PVZ-1' ];
		$this->meta[ Shipment_Fingerprint::META ] = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$freshness = new Shipment_Freshness( $registry );

		$this->assertTrue( $freshness->is_outdated( $this->order( [ 'get_shipping_city' => 'Тверь' ] ), $provider ) );

		$this->meta['_wc_cdek_pickup'] = [ 'id' => 'PVZ-2' ];

		$this->assertTrue( $freshness->is_outdated( $this->order(), $provider ) );
	}

	public function test_an_order_with_nothing_comparable_never_reads_its_pickup_point(): void {
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldNotReceive( 'get_provider_plugin' );

		$this->assertFalse( ( new Shipment_Freshness( $registry ) )->is_outdated( $this->order(), $this->provider() ) );
	}

	public function test_a_pickup_point_the_carrier_did_not_store_whole_is_read_from_the_checkout_pickup_slot(): void {
		$handler = Mockery::mock( Checkout_Handler::class );
		$handler->shouldReceive( 'read_values' )->andReturn( [ 'cdek_point' => 'PVZ-7' ] );
		$handler->shouldReceive( 'pickup_field_ids' )->andReturn( [ 'cdek_point' ] );

		$plugin = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_checkout_handler' )->andReturn( $handler );

		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'get_provider_plugin' )->with( 'cdek' )->andReturn( $plugin );

		$this->meta[ Shipment_Fingerprint::META ] = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-7' );

		$freshness = new Shipment_Freshness( $registry );

		$this->assertFalse( $freshness->is_outdated( $this->order(), $this->provider() ) );
		$this->assertTrue( $freshness->is_outdated( $this->order( [ 'get_shipping_city' => 'Тверь' ] ), $this->provider() ) );
	}
}
