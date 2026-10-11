<?php
/**
 * Checkout locality order-meta persistence for classic and Store API orders.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

final class Checkout_Order_Location_Fake_Service extends Location_Service {
	private bool $active;
	private ?Location_Record $record;

	public function __construct( bool $active, ?Location_Record $record ) {
		$this->active = $active;
		$this->record = $record;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return $this->record;
	}
}

final class Checkout_Order_Location_Probe extends Checkout_Handler {
	private bool $framework_method;

	public function __construct( Location_Service $service, bool $framework_method = true ) {
		parent::__construct( new Checkout_Fields(), '', $service );
		$this->framework_method = $framework_method;
		$this->set_requires_pickup_methods( [] );
	}

	protected function is_framework_shipping_method( string $method_id ): bool {
		return $this->framework_method && 'carrier_method' === $method_id;
	}

	public function save( $order, array $values, ?string $chosen_shipping_method = null ): void {
		// This test pins only the locality write, which follows this shared checkout save seam.
	}

	public function commit_for_test( \WC_Order $order, string $method ): void {
		$this->commit_values( $order, [], $method );
	}
}

/** @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::remember_checkout_location */
final class CheckoutHandlerOrderLocationTest extends TestCase {
	/** @var array<string, mixed> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->meta = [];
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'has_action' )->justReturn( 0 );
	}

	private function record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'cdek:city-1',
				'provider_id' => 'cdek',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				'raw'         => [ 'token' => 'never persist' ],
			]
		);
	}

	private function order( string $city = 'Москва', string $country = 'RU', string $method = 'carrier_method' ): \WC_Order {
		$order = \Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( $city );
		$order->shouldReceive( 'get_shipping_country' )->andReturn( $country );
		$order->shouldReceive( 'get_billing_city' )->andReturn( 'Billing city' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( 'RU' );
		$order->shouldReceive( 'get_items' )->with( 'shipping' )->andReturn(
			[
				new class( $method ) {
					private string $method_id;
					public function __construct( string $method_id ) {
						$this->method_id = $method_id;
					}
					public function get_method_id(): string {
						return $this->method_id;
					}
				},
			]
		);

		return $order;
	}

	public function test_classic_checkout_persists_the_normalized_settlement_without_raw_provider_data(): void {
		$handler = new Checkout_Order_Location_Probe( new Checkout_Order_Location_Fake_Service( true, $this->record() ) );
		$handler->commit_for_test( $this->order(), 'carrier_method' );

		$this->assertSame( 'cdek:city-1', $this->meta[ Checkout_Handler::ORDER_LOCATION_META_KEY ]['key'] );
		$this->assertNull( $this->meta[ Checkout_Handler::ORDER_LOCATION_META_KEY ]['raw'] );
	}

	public function test_store_api_checkout_persists_the_settlement_from_the_order_shipping_line(): void {
		$handler = new Checkout_Order_Location_Probe( new Checkout_Order_Location_Fake_Service( true, $this->record() ) );
		$handler->handle_store_api_order_processed( $this->order() );

		$this->assertSame( 'cdek:city-1', $this->meta[ Checkout_Handler::ORDER_LOCATION_META_KEY ]['key'] );
	}

	public function test_it_does_not_persist_without_a_framework_method_or_matching_record(): void {
		$no_method = new Checkout_Order_Location_Probe( new Checkout_Order_Location_Fake_Service( true, $this->record() ), false );
		$no_method->commit_for_test( $this->order(), 'carrier_method' );
		$this->assertArrayNotHasKey( Checkout_Handler::ORDER_LOCATION_META_KEY, $this->meta );

		$wrong_city = new Checkout_Order_Location_Probe( new Checkout_Order_Location_Fake_Service( true, $this->record() ) );
		$wrong_city->commit_for_test( $this->order( 'Тверь' ), 'carrier_method' );
		$this->assertArrayNotHasKey( Checkout_Handler::ORDER_LOCATION_META_KEY, $this->meta );
	}
}
