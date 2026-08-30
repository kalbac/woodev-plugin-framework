<?php
/**
 * Unit tests for Provider_Selection_Scope — the framework's own, location-provider-backed
 * Selection_Scope (Task 15; issue #159; spec §4.5.4-5): current_locality() answers the
 * customer's current Location Provider layer record's key, '' when there is none (the
 * "seam refusing to answer" sentinel, gotcha `an-empty-domain-key-is-not-a-key`), and —
 * combined with Pickup_Selection — the provider-switch MISS spec D5 exists to guarantee: a
 * point remembered under one locality key is never recalled once current_locality() answers
 * a DIFFERENT one.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
	use Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope;
	use Woodev\Framework\Shipping\Pickup\Selection_Scope;
	use Woodev\Tests\Unit\TestCase;

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
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-provider-selection-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';

	/**
	 * Minimal `\WC_Session` stand-in — mirrors every other Task 4/5/15 test's own fake
	 * session (e.g. `Customer_Location_Store_Fake_Session`, `Pickup_Selection_Fake_Session`).
	 */
	final class Provider_Selection_Scope_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     Session key.
		 * @param mixed  $default Fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}
	}

	/**
	 * Probe substituting a {@see Provider_Selection_Scope_Fake_Session} (or `null`) for the
	 * real `WC()->session` global — same shape as `Customer_Location_Store_Probe`.
	 */
	final class Provider_Selection_Scope_Customer_Store_Probe extends Customer_Location_Store {

		private ?Provider_Selection_Scope_Fake_Session $fake_session;

		public function __construct( ?Provider_Selection_Scope_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * Probe substituting a {@see Provider_Selection_Scope_Fake_Session} (or `null`) for
	 * {@see Pickup_Selection}'s own `WC()->session` — same shape as
	 * `PickupSelectionTest`'s own `Pickup_Selection_Probe`, but reusing THIS test file's
	 * session double so a "provider switch" test can share the exact same fake between the
	 * customer-location store and the pickup-selection map without gluing two unrelated
	 * fakes together.
	 */
	final class Provider_Selection_Scope_Pickup_Selection_Probe extends Pickup_Selection {

		private ?Provider_Selection_Scope_Fake_Session $fake_session;

		public function __construct( Selection_Scope $scope, ?Provider_Selection_Scope_Fake_Session $fake_session ) {
			parent::__construct( $scope );
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * A {@see Location_Service} that never treats a stored record as STALE
	 * (#346/#333) — this file's own fixtures deliberately switch a record's
	 * `provider_id` mid-test (`dadata:X` -> `cdek:Y`, spec D5's provider-switch
	 * MISS) to prove `current_locality()` tracks whichever record is CURRENTLY
	 * stored; none of that is about whether a record's OWNING provider still
	 * resolves to it, which is `LocationServiceTest`'s own job. Overriding
	 * {@see Location_Service::is_customer_record_stale()} (a `protected` test
	 * seam for exactly this) is simpler here than registering a real owning
	 * provider per record, because a single record's `provider_id` in this
	 * file is not fixed the way it is in `PickupHandlerTest`'s own fixtures.
	 */
	final class Provider_Selection_Scope_Location_Service_Probe extends Location_Service {

		protected function is_customer_record_stale( Location_Record $record, ?string $for_country = null ): bool {
			return false;
		}
	}

	/**
	 * A minimal, concrete {@see Provider_Selection_Scope} — `current_locality()` is
	 * inherited (`final`, cannot be overridden); the other three methods are the plugin's
	 * own domain knowledge, exactly as {@see Provider_Selection_Scope}'s own docblock
	 * describes. `locality_for_point()` reads the locality key off `Pickup_Point`'s own
	 * `instruction` field — {@see Pickup_Point} carries no carrier-payload passthrough of
	 * its own to stash a locality key on, so this fixture repurposes an existing free-text
	 * field purely as a TEST double's carrier vocabulary (Selection_Scope's own docblock:
	 * "typically a field already present on it… whatever the carrier's own payload
	 * carries" — any field a real carrier's own point shape happens to expose works).
	 */
	final class Provider_Selection_Scope_Test_Scope extends Provider_Selection_Scope {

		public function session_key(): string {
			return 'pss_test_pickup_selection';
		}

		public function locality_for_point( Pickup_Point $point ): string {
			return $point->to_array()['instruction'] ?? '';
		}

		public function type_for_method( string $method_id ): ?string {
			return '' === $method_id ? null : Selection_Scope::TYPE_ANY;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope
	 */
	final class ProviderSelectionScopeTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// A fresh gate every test — Location_Provider_Registry::instance() is a
			// process-wide singleton (same discipline LocationServiceTest's own setUp
			// documents), and get_default_locality_policy() must see the gate CLOSED so
			// resolve_default() answers null with no further WordPress calls.
			Location_Provider_Registry::instance()->reset_for_tests();

			Functions\when( 'is_user_logged_in' )->justReturn( false );

			// Customer_Location_Store::get_chain() now calls retention_ttl_seconds()
			// (issue #356 part 3) on every read too — 'not configured' keeps every
			// test in this file, none of which is about retention, from expiring a
			// fixture (same discipline as LocationServiceTest's own setUp() for
			// wc_get_base_location()).
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );
		}

		private function record( string $key = 'dadata:fias-1' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				]
			);
		}

		private function point( string $locality_key ): Pickup_Point {
			return Pickup_Point::from_array(
				[
					'id'          => 'PT-1',
					'name'        => 'Test point',
					'address'     => 'ул. Тестовая, 1',
					'locality'    => 'Москва',
					'lat'         => 55.75,
					'lng'         => 37.61,
					'type'        => [ 'code' => 'pvz', 'label' => 'ПВЗ' ],
					'instruction' => $locality_key,
				]
			);
		}

		// -------------------------------------------------------------------
		// current_locality(): the empty-key discipline (an-empty-domain-key-is-not-a-key)
		// -------------------------------------------------------------------

		public function test_current_locality_is_empty_when_the_customer_has_no_record_yet(): void {
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( '', $scope->current_locality() );
		}

		public function test_current_locality_returns_the_customer_record_key(): void {
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$store->set( $this->record( 'dadata:fias-1' ) );

			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( 'dadata:fias-1', $scope->current_locality() );
		}

		public function test_current_locality_tracks_the_store_live_never_caching_across_calls(): void {
			// Task 15's whole point: the client asks for the CURRENT key on every request,
			// never a value cached at construction time — a provider switch (or simply the
			// customer choosing a new locality) must be visible on the very next call.
			$store   = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( '', $scope->current_locality() );

			$store->set( $this->record( 'dadata:fias-1' ) );
			$this->assertSame( 'dadata:fias-1', $scope->current_locality() );

			$store->set( $this->record( 'cdek:city-77' ) );
			$this->assertSame( 'cdek:city-77', $scope->current_locality() );
		}

		// -------------------------------------------------------------------
		// current_locality() answers the SETTLEMENT level, never the current one
		// (issue #334; location-chain design §5)
		// -------------------------------------------------------------------

		/**
		 * An ADDRESS-level record inside `$settlement_key`, shaped the way the
		 * DaData provider publishes one: its own key is the house/street id, and
		 * the settlement it sits in is published in the ancestor SET.
		 *
		 * @param string $settlement_key The settlement this address belongs to.
		 * @param string $key            The address record's own key.
		 *
		 * @return Location_Record
		 */
		private function address_record( string $settlement_key, string $key = 'dadata:fias-addr-1' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_ADDRESS,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
					'street'      => [ 'name' => 'Цветной', 'type' => 'б-р' ],
					'house'       => '1',
					'ancestors'   => [ $settlement_key ],
				]
			);
		}

		public function test_current_locality_stays_on_the_settlement_after_the_customer_picks_an_address(): void {
			// Issue #334: `/location/select` is posted for EVERY level including address, so the
			// CURRENT record becomes address-level the moment the customer refines their address.
			// The pickup selection was written under the settlement key and read back under the
			// address key — the chosen point became unreachable, silently.
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$store->set( $this->record( 'dadata:fias-1' ) );
			$store->set( $this->address_record( 'dadata:fias-1' ) );

			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame(
				'dadata:fias-1',
				$scope->current_locality(),
				'refining the address inside a settlement must not move the pickup locality'
			);
		}

		public function test_current_locality_refuses_rather_than_falling_back_to_an_address_key(): void {
			// The customer typed an address without ever picking a settlement, so the chain holds
			// no settlement at all. `''` — the layer's own "refusing to answer" sentinel, which
			// Pickup_Selection refuses on both the write and the read side — is the only safe
			// answer here: falling back to the address record's own key is EXACTLY the #334 bug,
			// and deriving a settlement from the address is the ambiguity gotcha
			// `a-derived-ancestor-is-not-the-one-the-customer-picked` measures.
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$store->set( $this->address_record( 'dadata:fias-1' ) );

			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( '', $scope->current_locality() );
		}

		public function test_current_locality_is_empty_for_a_region_only_record(): void {
			// A region is SHALLOWER than a settlement — there is no settlement to key by, and a
			// region key would collect points from every city in it under one bucket.
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$store->set(
				Location_Record::from_array(
					[
						'key'         => 'dadata:fias-region-1',
						'provider_id' => 'dadata',
						'level'       => Location_Record::LEVEL_REGION,
						'country'     => 'RU',
						'region'      => [ 'name' => 'Московская', 'type' => 'обл' ],
					]
				)
			);

			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( '', $scope->current_locality() );
		}

		public function test_current_locality_follows_the_customer_to_a_new_settlement(): void {
			// The mirror of the fix: an address pick must NOT move the locality, but a settlement
			// pick still must — otherwise the point of one city would be recalled in another.
			$store = new Provider_Selection_Scope_Customer_Store_Probe( new Provider_Selection_Scope_Fake_Session() );
			$store->set( $this->record( 'dadata:fias-1' ) );
			$store->set( $this->address_record( 'dadata:fias-1' ) );
			$store->set( $this->record( 'dadata:fias-2' ) );

			$service = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope   = new Provider_Selection_Scope_Test_Scope( $service );

			$this->assertSame( 'dadata:fias-2', $scope->current_locality() );
		}

		// -------------------------------------------------------------------
		// Provider-switch MISS (spec D5) — Provider_Selection_Scope + Pickup_Selection
		// -------------------------------------------------------------------

		public function test_a_point_remembered_under_one_provider_key_is_not_offered_under_a_different_one(): void {
			$customer_session = new Provider_Selection_Scope_Fake_Session();
			$store            = new Provider_Selection_Scope_Customer_Store_Probe( $customer_session );
			$service          = new Provider_Selection_Scope_Location_Service_Probe( Location_Provider_Registry::instance(), $store );
			$scope            = new Provider_Selection_Scope_Test_Scope( $service );

			$pickup_session = new Provider_Selection_Scope_Fake_Session();
			$selection      = new Provider_Selection_Scope_Pickup_Selection_Probe( $scope, $pickup_session );

			// The customer is currently in the DaData-resolved locality and confirms a point
			// there — locality_for_point() reads the point's OWN locality_key, "dadata:X".
			$store->set( $this->record( 'dadata:X' ) );
			$selection->remember(
				$scope->locality_for_point( $this->point( 'dadata:X' ) ),
				Selection_Scope::TYPE_ANY,
				'PT-1'
			);

			$this->assertSame(
				'PT-1',
				$selection->recall( 'dadata:X', Selection_Scope::TYPE_ANY ),
				'sanity: the point IS recalled under the key it was remembered under'
			);

			// The store's active provider switches (or the customer's own default-locality
			// re-resolution lands on a different provider, spec §4.6) — current_locality()
			// now answers a DIFFERENT namespace for the SAME real-world city.
			$store->set( $this->record( 'cdek:Y' ) );

			$this->assertSame( 'cdek:Y', $scope->current_locality() );

			// The map now addresses points under "cdek:Y" (Task 15's own Point_Query
			// addressing) and restore-time persistence keys off the SAME current_locality() —
			// see Pickup_Handler::current_selection_pair(). The point remembered under
			// "dadata:X" must MISS here — never be misread as belonging to "cdek:Y".
			$this->assertNull( $selection->recall( 'cdek:Y', Selection_Scope::TYPE_ANY ) );
			$this->assertNull( $selection->recall_latest( 'cdek:Y' ) );

			// The old entry is not lost — returning to the original locality still finds it
			// (Pickup_Selection's whole reason for being a MAP, not a single slot).
			$store->set( $this->record( 'dadata:X' ) );
			$this->assertSame( 'PT-1', $selection->recall( $scope->current_locality(), Selection_Scope::TYPE_ANY ) );
		}
	}
}
