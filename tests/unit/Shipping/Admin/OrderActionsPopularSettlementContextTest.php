<?php
/**
 * Unit tests for Order_Actions::resolve_popular_settlement_context() — the fix for round 2
 * critic finding HIGH 2 ("the enrolment seam is inert"): the export action is the ONE real
 * framework caller of {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()},
 * and before this fix nothing in the repo ever supplied its settlement/provider params.
 * This proves the real call site now genuinely resolves both — via
 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlement_Store::recall_candidate()}
 * and, since round 3 (HIGH 2), the SAME provider that produced the recalled
 * settlement (looked up by the settlement's own `provider_id()` through
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_providers()}),
 * NOT {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_active_provider()}
 * — a merchant switching the active provider between checkout and export must never
 * hand `enroll()` a provider that disagrees with the record's own `provider_id()`
 * (it throws `\InvalidArgumentException` when it does, AFTER the carrier order
 * already exists).
 *
 * Card #1016: the order-edit metabox no longer keeps its own copy of this resolver or its own
 * store — it reaches it through {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::perform()},
 * which reads the framework's SHARED store (the registry singleton's). The tests here stamp the
 * store onto that singleton, so they also pin that default: remove it and enrolment into the
 * popular-settlements list switches off silently for every surface.
 *
 * The resolver is private, so it is reached by reflection.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/orders/class-order-actions.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;

	/**
	 * A minimal, configurable-id Location_Provider fixture — used to prove
	 * resolve_popular_settlement_context() looks the provider up by the
	 * settlement's own provider_id(), not by whichever provider happens to be
	 * active (round 3, HIGH 2).
	 */
	class Order_Actions_Fixture_Provider extends Abstract_Location_Provider {

		private string $id;

		public function __construct( string $id ) {
			$this->id = $id;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return $this->id;
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
}

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::resolve_popular_settlement_context
	 */
	final class OrderActionsPopularSettlementContextTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Location_Provider_Registry is a process-wide singleton; other test
			// files configure it with declared providers/settings, so this test must
			// not observe (or leave behind) that state.
			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * Reads a private property of the registry singleton, for {@see self::stamp()}.
		 */
		private function registry_property( string $name ): \ReflectionProperty {
			$property = ( new \ReflectionClass( Location_Provider_Registry::class ) )->getProperty( $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			return $property;
		}

		/**
		 * Stamps `$store` onto the registry singleton — the shared store
		 * {@see Order_Actions} reads — and returns the performer.
		 *
		 * @param Popular_Settlement_Store $store
		 * @return Order_Actions
		 */
		private function actions_with_store( Popular_Settlement_Store $store ): Order_Actions {
			$this->registry_property( 'popular_settlement_store' )->setValue( Location_Provider_Registry::instance(), $store );

			$reflection = new \ReflectionClass( Order_Actions::class );

			// The constructor only stores the Orders_Registry, which this resolver never touches.
			return $reflection->newInstanceWithoutConstructor();
		}

		/**
		 * @param Order_Actions $actions
		 * @param mixed         $order
		 * @return array{0: Location_Record|null, 1: mixed}
		 */
		private function invoke( Order_Actions $actions, $order ): array {
			$method = new \ReflectionMethod( Order_Actions::class, 'resolve_popular_settlement_context' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			return $method->invoke( $actions, $order );
		}

		/**
		 * Stamps `$providers` directly onto the REAL registry singleton's backing
		 * array, bypassing {@see Location_Provider_Registry::collect()} entirely
		 * (its bundled-provider registration and `register_settings()` call are
		 * unrelated to what the resolver exercises — `get_providers()` only ever
		 * reads this array).
		 *
		 * @param array<int, \Woodev\Framework\Shipping\Location\Location_Provider> $providers
		 * @return void
		 */
		private function register_providers( array $providers ): void {
			$indexed = [];
			foreach ( $providers as $provider ) {
				$indexed[ $provider->get_id() ] = $provider;
			}

			$this->registry_property( 'providers' )->setValue( Location_Provider_Registry::instance(), $indexed );
		}

		/**
		 * No candidate was recalled (e.g. no active provider at checkout, or the
		 * customer's session already expired): resolves to [null, null] without
		 * ever asking the registry for a provider.
		 */
		public function test_resolves_to_null_null_when_no_candidate_was_recalled(): void {
			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'recall_candidate' )->once()->with( $order )->andReturn( null );

			[ $settlement, $provider ] = $this->invoke( $this->actions_with_store( $store ), $order );

			$this->assertNull( $settlement );
			$this->assertNull( $provider );
		}

		/**
		 * The core round-3 HIGH 2 regression proof: the provider returned is the
		 * one registered under the RECALLED SETTLEMENT'S OWN `provider_id()`,
		 * resolved purely via {@see Location_Provider_Registry::get_providers()} —
		 * this test never configures (or even needs) an "active" provider at all,
		 * which is exactly the point: the round-2 behaviour this replaces asked
		 * {@see Location_Provider_Registry::get_active_provider()} for "whichever
		 * provider is active now", a question with a completely different answer
		 * that could hand `enroll()` a provider disagreeing with the record's own
		 * `provider_id()` — which throws AFTER the carrier order already exists.
		 * Two providers are registered (one is never even asked about) to prove the
		 * lookup is genuinely keyed by id, not "whatever's first"/"whatever's
		 * active".
		 */
		public function test_resolves_the_provider_that_produced_the_settlement(): void {
			$this->register_providers(
				[
					new \Order_Actions_Fixture_Provider( 'acme' ),
					new \Order_Actions_Fixture_Provider( 'other-carrier' ),
				]
			);

			$record = Location_Record::from_array(
				[
					'key'         => 'other-carrier:1',
					'provider_id' => 'other-carrier',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'recall_candidate' )->once()->with( $order )->andReturn( $record );

			[ $settlement, $provider ] = $this->invoke( $this->actions_with_store( $store ), $order );

			$this->assertSame( $record, $settlement );
			$this->assertNotNull( $provider );
			$this->assertSame( 'other-carrier', $provider->get_id(), 'The provider must be the one that produced the settlement, resolved by its own provider_id().' );
		}

		/**
		 * The settlement's own provider is no longer registered (e.g. a carrier
		 * plugin was deactivated between checkout and export): resolves to
		 * [settlement, null] rather than falling back to any other provider.
		 */
		public function test_resolves_null_provider_when_the_settlements_own_provider_is_no_longer_registered(): void {
			$this->register_providers( [ new \Order_Actions_Fixture_Provider( 'acme' ) ] );

			$record = Location_Record::from_array(
				[
					'key'         => 'ghost:1',
					'provider_id' => 'ghost',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'recall_candidate' )->once()->with( $order )->andReturn( $record );

			[ $settlement, $provider ] = $this->invoke( $this->actions_with_store( $store ), $order );

			$this->assertSame( $record, $settlement );
			$this->assertNull( $provider );
		}
	}
}
