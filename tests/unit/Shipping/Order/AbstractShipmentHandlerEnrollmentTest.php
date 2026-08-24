<?php
/**
 * Unit tests for Abstract_Shipment_Handler's popular-settlements enrolment seam
 * (#488 slice 2), driven through the REAL export() method (round 2, MEDIUM 4: the
 * round-1 version of this file bypassed export() entirely via reflection and
 * invoked the protected helper directly, so it would have stayed green even if the
 * call to enroll_popular_settlement() were deleted from export()). Also covers the
 * round-2 MEDIUM 3 fix: an export whose extractor returns an empty carrier id must
 * NOT enrol, even though it neither threw nor was otherwise rejected.
 *
 * The D4/D4a gates, the two clocks, ranking, eviction, and the foreign-provider_id
 * exclusion are covered directly on
 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlement_Store} in
 * PopularSettlementStoreTest.php; this file only proves export()'s own wiring.
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
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/api/interface-shipping-api.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-async-request.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-background-job-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;

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
	 * Minimal concrete subclass. `$next_carrier_order_id` is a test-controlled seam
	 * so a single fixture can drive both "export produced a real id" and "export
	 * produced no id" (MEDIUM 3) without needing a real carrier response shape.
	 */
	class Test_Shipment_Handler extends Abstract_Shipment_Handler {

		/** @var string what extract_carrier_order_id() returns for the next call */
		public string $next_carrier_order_id = 'CARRIER-1';

		protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
			return $this->next_carrier_order_id;
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::enroll_popular_settlement
	 */
	final class AbstractShipmentHandlerEnrollmentTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Location_Provider_Registry is a process-wide singleton; other test
			// files configure it with declared providers/settings, so tests exercising
			// the constructor's default-store resolution (round 3, HIGH 1) must not
			// observe (or leave behind) that state.
			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
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

		/**
		 * Builds a Test_Shipment_Handler through its REAL constructor.
		 *
		 * @param Popular_Settlement_Store|null $store
		 * @param mixed                         $api           A Shipping_API mock; a default success double is built when null.
		 * @return \Test_Shipment_Handler
		 */
		private function handler( ?Popular_Settlement_Store $store, $api = null ): \Test_Shipment_Handler {
			if ( null === $api ) {
				$response = Mockery::mock( '\Woodev_API_Response' );
				$api      = Mockery::mock( '\Woodev\Framework\Shipping\Shipping_API' );
				$api->shouldReceive( 'create_order' )->andReturn( $response );
			}

			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'set' )->withAnyArgs();

			$retry_handler = Mockery::mock( '\Woodev_Background_Job_Handler' );

			return new \Test_Shipment_Handler( $api, $order_handler, $retry_handler, 'test', $store );
		}

		/**
		 * A real export() call, with a non-empty carrier id, DOES enrol — driven
		 * end-to-end through export(), not via reflection (round 2, MEDIUM 4).
		 */
		public function test_export_enrolls_when_the_carrier_id_is_non_empty(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();
			$record   = $this->record();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'enroll' )->once()->with( $provider, $record );

			$handler                        = $this->handler( $store );
			$handler->next_carrier_order_id = 'CARRIER-1';

			$order  = Mockery::mock( '\WC_Order' );
			$result = $handler->export( $order, $record, $provider );

			$this->assertSame( 'CARRIER-1', $result );
		}

		/**
		 * MEDIUM 3: a non-throwing response whose extractor yields an EMPTY carrier
		 * id must NOT enrol — an export that produced no tracking id is not evidence
		 * the shop shipped to that settlement.
		 */
		public function test_export_does_not_enroll_when_the_carrier_id_is_empty(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();
			$record   = $this->record();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'enroll' );

			$handler                        = $this->handler( $store );
			$handler->next_carrier_order_id = '';

			$order  = Mockery::mock( '\WC_Order' );
			$result = $handler->export( $order, $record, $provider );

			$this->assertSame( '', $result );
		}

		/**
		 * Round 3, HIGH 1: no production construction site in this repo ever
		 * supplies a store (see the class docblock on
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::$popular_settlement_store}),
		 * so a null constructor argument must resolve the framework's OWN shared
		 * instance — {@see Location_Provider_Registry::popular_settlement_store()} —
		 * the SAME one {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order}
		 * resolves against, rather than leaving enrolment permanently disabled.
		 */
		public function test_constructor_defaults_to_the_frameworks_shared_store_when_none_is_injected(): void {
			$handler = $this->handler( null );

			$property = ( new \ReflectionObject( $handler ) )->getProperty( 'popular_settlement_store' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			$this->assertSame(
				Location_Provider_Registry::instance()->popular_settlement_store(),
				$property->getValue( $handler ),
				'A handler constructed without an injected store must default to the framework\'s shared instance.'
			);
		}

		/**
		 * Round 3, HIGH 2 (part 2): enrolment is a ranking side-effect that runs
		 * AFTER the carrier order already exists (the id is already persisted and
		 * `shipment_exported` has already fired). A failure inside `enroll()` —
		 * most notably the `\InvalidArgumentException` it throws on a
		 * provider/record mismatch — must never undo or fail the export; it is
		 * swallowed and logged, not propagated.
		 */
		public function test_export_still_completes_when_enrolment_throws(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();
			$record   = $this->record();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'enroll' )->once()->with( $provider, $record )->andThrow(
				new \InvalidArgumentException( 'record provider_id does not match the given provider' )
			);

			$handler = $this->handler( $store );
			$order   = Mockery::mock( '\WC_Order' );

			$result = $handler->export( $order, $record, $provider );

			$this->assertSame( 'CARRIER-1', $result, 'export() must still report success — enrolment failing must not undo a real export.' );
		}

		public function test_export_is_a_no_op_when_the_settlement_is_unknown(): void {
			$provider = new \ShipmentHandlerEnrollment_Fixture_Provider();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'enroll' );

			$handler = $this->handler( $store );
			$order   = Mockery::mock( '\WC_Order' );

			$handler->export( $order, null, $provider );
		}

		public function test_export_is_a_no_op_when_the_provider_is_unknown(): void {
			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'enroll' );

			$handler = $this->handler( $store );
			$order   = Mockery::mock( '\WC_Order' );

			$handler->export( $order, $this->record(), null );
		}
	}
}
