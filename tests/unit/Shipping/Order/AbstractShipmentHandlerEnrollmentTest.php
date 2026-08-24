<?php
/**
 * Unit tests for Abstract_Shipment_Handler's popular-settlements enrolment seam
 * (#488 slice 2): a successful export bumps the popular list when the caller
 * supplies both a settlement and its producing provider, and is a strict no-op
 * whenever the store, the settlement, or the provider is missing.
 *
 * Constructed via reflection (`newInstanceWithoutConstructor()` + a reflected
 * property/method) rather than the real constructor — Abstract_Shipment_Handler's
 * constructor type-hints Shipping_API / Shipping_Order_Handler /
 * Woodev_Background_Job_Handler, none of which this seam touches, so pulling in
 * their full dependency chains (a real WC_Order double, Woodev_Async_Request, …)
 * would test infrastructure this change never exercises. The D4/D4a gates, the two
 * clocks, ranking, eviction, and the foreign-provider_id exclusion are already
 * covered directly on {@see \Woodev\Framework\Shipping\Location\Popular_Settlement_Store}
 * in PopularSettlementStoreTest.php; this file only proves the wiring.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	/**
	 * Minimal concrete Location_Provider fixture declaring CAPABILITY_RESOLVE_KEY.
	 */
	class ShipmentHandlerEnrollment_Fixture_Provider extends Abstract_Location_Provider {
		public function get_id(): string {
			return 'dadata';
		}

		public function get_name(): string {
			return 'Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function resolve_key( string $key ): ?Location_Record {
			return null;
		}
	}

	/**
	 * Minimal concrete subclass — only implements the one abstract method so the
	 * class can be instantiated (via reflection, bypassing __construct()).
	 */
	class Test_Shipment_Handler extends Abstract_Shipment_Handler {
		protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
			return '';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::enroll_popular_settlement
	 */
	final class AbstractShipmentHandlerEnrollmentTest extends TestCase {

		/**
		 * Builds a Test_Shipment_Handler without running its real constructor, and
		 * reflectively stamps the given popular-settlements store onto it.
		 *
		 * @param Popular_Settlement_Store|null $store
		 * @return \Test_Shipment_Handler
		 */
		private function handler_with_store( ?Popular_Settlement_Store $store ): \Test_Shipment_Handler {
			$reflection = new \ReflectionClass( \Test_Shipment_Handler::class );
			$handler    = $reflection->newInstanceWithoutConstructor();

			$property = $reflection->getProperty( 'popular_settlement_store' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $handler, $store );

			return $handler;
		}

		/**
		 * Invokes the protected enroll_popular_settlement() seam via reflection.
		 *
		 * @param \Test_Shipment_Handler $handler
		 * @param Location_Record|null   $settlement
		 * @param mixed                  $provider
		 * @return void
		 */
		private function invoke_enroll( \Test_Shipment_Handler $handler, ?Location_Record $settlement, $provider ): void {
			$method = ( new \ReflectionClass( $handler ) )->getMethod( 'enroll_popular_settlement' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$method->invoke( $handler, $settlement, $provider );
		}

		private function record(): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => 'dadata:1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);
		}

		public function test_enrolls_when_store_settlement_and_provider_are_all_present(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();
			$record   = $this->record();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'enroll' )->once()->with( $provider, $record );

			$handler = $this->handler_with_store( $store );

			$this->invoke_enroll( $handler, $record, $provider );
		}

		public function test_is_a_no_op_when_no_store_was_supplied(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();

			$handler = $this->handler_with_store( null );

			// No store to assert against — the point is that this does not fatal.
			$this->invoke_enroll( $handler, $this->record(), $provider );

			$this->addToAssertionCount( 1 );
		}

		public function test_is_a_no_op_when_the_settlement_is_unknown(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'enroll' );

			$handler = $this->handler_with_store( $store );

			$this->invoke_enroll( $handler, null, $provider );
		}

		public function test_is_a_no_op_when_the_provider_is_unknown(): void {
			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'enroll' );

			$handler = $this->handler_with_store( $store );

			$this->invoke_enroll( $handler, $this->record(), null );
		}
	}
}
