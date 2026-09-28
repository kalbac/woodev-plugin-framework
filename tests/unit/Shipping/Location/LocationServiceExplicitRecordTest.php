<?php
/**
 * Unit tests for {@see Location_Service::with_explicit_record()} — #965, spec D2 «Mine 3».
 *
 * The admin rate calculator prices a package for a destination the manager typed in. Carrier rate
 * code reads its destination through the customer accessors, which would otherwise answer the
 * ADMIN's own stored record (or lazily seed a default into it). Inside `with_explicit_record()`
 * they answer the explicit record instead, and the store is neither read nor written.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Mockery;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Resolution_Cache;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
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

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Location\Location_Service
	 */
	final class LocationServiceExplicitRecordTest extends TestCase {

		/** @var Customer_Location_Store&\Mockery\MockInterface */
		private $store;

		/** @var Location_Resolution_Cache&\Mockery\MockInterface */
		private $cache;

		/** @var Location_Service */
		private Location_Service $service;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Location_Provider_Registry::instance()->reset_for_tests();

			$this->store   = Mockery::mock( Customer_Location_Store::class );
			$this->cache   = Mockery::mock( Location_Resolution_Cache::class );
			$this->service = new Location_Service( Location_Provider_Registry::instance(), $this->store, $this->cache );
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();
			parent::tearDown();
		}

		/**
		 * A REGION-level record: the chain derivation (a provider round trip) only runs for a
		 * settlement, so this keeps the test about the override alone.
		 *
		 * @return Location_Record
		 */
		private function record(): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => 'test:region:moscow',
					'provider_id' => 'test',
					'level'       => Location_Record::LEVEL_REGION,
					'country'     => 'RU',
					'label'       => 'Москва',
				]
			);
		}

		/**
		 * @covers ::with_explicit_record
		 * @covers ::get_customer_record
		 *
		 * @return void
		 */
		public function test_the_record_accessor_answers_the_explicit_record_and_never_touches_the_store(): void {
			$this->store->shouldNotReceive( 'get_chain' );
			$this->store->shouldNotReceive( 'get' );
			$this->store->shouldNotReceive( 'set' );

			$record = $this->record();

			$entry = $this->service->with_explicit_record(
				$record,
				function () {
					return $this->service->get_customer_record();
				}
			);

			$this->assertSame( $record, $entry['record'] );
			$this->assertFalse( $entry['implicit'], 'an explicit destination is never an implicit default' );
		}

		/**
		 * @covers ::get_customer_chain
		 * @covers ::get_customer_record_at
		 *
		 * @return void
		 */
		public function test_the_chain_and_level_accessors_are_built_from_the_explicit_record(): void {
			$this->store->shouldNotReceive( 'get_chain' );

			$record = $this->record();

			[ $chain, $at_region, $at_settlement ] = $this->service->with_explicit_record(
				$record,
				function () {
					return [
						$this->service->get_customer_chain(),
						$this->service->get_customer_record_at( Location_Record::LEVEL_REGION ),
						$this->service->get_customer_record_at( Location_Record::LEVEL_SETTLEMENT ),
					];
				}
			);

			$this->assertSame( [ Location_Record::LEVEL_REGION => $record ], $chain['records'] );
			$this->assertSame( Location_Record::LEVEL_REGION, $chain['current'] );
			$this->assertFalse( $chain['implicit'] );
			$this->assertSame( $record, $at_region );
			$this->assertNull( $at_settlement, 'a level the explicit record is not at is absent, not read from the store' );
		}

		/**
		 * `resolve_for( $plugin )` with no record — what carrier rate code calls — must resolve for
		 * the explicit record through the resolution cache.
		 *
		 * @covers ::resolve_for
		 *
		 * @return void
		 */
		public function test_resolve_for_without_a_record_resolves_for_the_explicit_record(): void {
			$this->store->shouldNotReceive( 'get_chain' );

			$record = $this->record();
			$plugin = Mockery::mock( Shipping_Plugin::class );

			$this->cache->shouldReceive( 'resolve_for' )->once()->with( $plugin, $record )->andReturn( 'carrier-city-44' );

			$identity = $this->service->with_explicit_record(
				$record,
				function () use ( $plugin ) {
					return $this->service->resolve_for( $plugin );
				}
			);

			$this->assertSame( 'carrier-city-44', $identity );
		}

		/**
		 * «No destination record» is an answer, not a fall-through: the accessors say `null`
		 * and the admin's own store is left alone (no read, no lazy default write).
		 *
		 * @covers ::with_explicit_record
		 *
		 * @return void
		 */
		public function test_a_null_record_answers_none_instead_of_falling_back_to_the_store(): void {
			$this->store->shouldNotReceive( 'get_chain' );
			$this->store->shouldNotReceive( 'set' );
			$this->cache->shouldNotReceive( 'resolve_for' );

			$plugin = Mockery::mock( Shipping_Plugin::class );

			[ $entry, $chain, $identity ] = $this->service->with_explicit_record(
				null,
				function () use ( $plugin ) {
					return [
						$this->service->get_customer_record(),
						$this->service->get_customer_chain(),
						$this->service->resolve_for( $plugin ),
					];
				}
			);

			$this->assertNull( $entry );
			$this->assertNull( $chain );
			$this->assertNull( $identity );
		}

		/**
		 * The override is scoped to the call: after it — including after a throw — the store is
		 * read again, and nested calls unwind to the outer record.
		 *
		 * @covers ::with_explicit_record
		 *
		 * @return void
		 */
		public function test_the_override_is_restored_after_the_call_even_when_the_callback_throws(): void {
			$this->store->shouldReceive( 'get_chain' )->once()->andReturn( null );

			try {
				$this->service->with_explicit_record(
					$this->record(),
					static function () {
						throw new \RuntimeException( 'carrier blew up' );
					}
				);
				$this->fail( 'the callback exception must propagate' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'carrier blew up', $exception->getMessage() );
			}

			// The store IS consulted again (get_chain expected once above). Registry is closed →
			// no default resolves, so the accessor answers null.
			$this->assertNull( $this->service->get_customer_record() );
		}

		/**
		 * @covers ::with_explicit_record
		 *
		 * @return void
		 */
		public function test_nested_calls_unwind_to_the_outer_record(): void {
			$outer = $this->record();
			$inner = Location_Record::from_array(
				[
					'key'         => 'test:region:spb',
					'provider_id' => 'test',
					'level'       => Location_Record::LEVEL_REGION,
					'country'     => 'RU',
					'label'       => 'Санкт-Петербург',
				]
			);

			$seen = $this->service->with_explicit_record(
				$outer,
				function () use ( $inner ) {
					$during_inner = $this->service->with_explicit_record(
						$inner,
						function () {
							return $this->service->get_customer_record()['record'];
						}
					);

					return [ $during_inner, $this->service->get_customer_record()['record'] ];
				}
			);

			$this->assertSame( [ $inner, $outer ], $seen );
		}
	}
}
