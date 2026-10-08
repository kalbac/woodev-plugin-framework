<?php
/**
 * City_Limit — the city limit's storage, its decision and its zone scope (#1176).
 *
 * The decision is the part that must not be wrong in either direction: a method hidden for a customer it should
 * serve loses a sale, a method shown where the merchant forbade it ships somewhere he cannot. The tests pin both
 * directions, and the two fail-open rules the coordinator decided (no customer record → available; stale cities →
 * ignored).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\City_Limit;
	use Woodev\Framework\Shipping\Location\Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-city-limit.php';

	/** A provider that is only an id. */
	final class City_Limit_Test_Provider extends Abstract_Location_Provider {

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
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}
	}

	/** A Location_Service that asks no registry: which provider owns a level, who the customer is. */
	class City_Limit_Test_Service extends Location_Service {

		/** @var string|null the provider id every level resolves to (`null`: nobody serves it). */
		public ?string $owner = 'test-cdek';

		/** @var Location_Record|null */
		public ?Location_Record $customer = null;

		/** @var bool */
		public bool $throws = false;

		/** @var bool whether the customer's chain is the store's guessed default rather than a pick */
		public bool $implicit = false;

		/** @var string|null the country the customer record was asked for. */
		public ?string $asked_country = null;

		/** @var Location_Record|null what a stale city re-resolves to. */
		public ?Location_Record $replacement = null;

		/** @var bool */
		public bool $active = true;

		/** @var bool */
		public bool $region_removed = false;

		/** @var string|null what the WooCommerce state of any record is. */
		public ?string $state_code = null;

		public function __construct() {}

		public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
			return null === $this->owner ? null : new City_Limit_Test_Provider( $this->owner );
		}

		public function get_customer_chain( ?string $for_country = null ): ?array {
			if ( $this->throws ) {
				throw new \RuntimeException( 'provider down' );
			}

			$this->asked_country = $for_country;

			if ( null === $this->customer ) {
				return null;
			}

			return [
				'records'  => [ Location_Record::LEVEL_SETTLEMENT => $this->customer ],
				'current'  => Location_Record::LEVEL_SETTLEMENT,
				'implicit' => $this->implicit,
				'saved_at' => 0,
			];
		}

		public function reresolve_stranded_record( Location_Record $stored ): ?Location_Record {
			return $this->replacement;
		}

		public function is_active(): bool {
			return $this->active;
		}

		public function is_region_field_removed(): bool {
			return $this->region_removed;
		}

		public function resolve_default_country(): string {
			return 'RU';
		}

		public function wc_state_code_for_record( Location_Record $record ): ?string {
			return $this->state_code;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\City_Limit
	 */
	final class CityLimitTest extends TestCase {

		protected function tearDown(): void {
			City_Limit::use_continents_for_tests( null );

			parent::tearDown();
		}

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wp_json_encode' )->alias(
				static fn( $data, $flags = 0 ) => json_encode( $data, $flags )
			);
		}

		/**
		 * @param string $key      locality key.
		 * @param string $label    display label.
		 * @param array  $override record fields to replace.
		 *
		 * @return array<string, mixed>
		 */
		private function city_data( string $key, string $label, array $override = [] ): array {
			return array_merge(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => 'settlement',
					'country'     => 'RU',
					'region'      => [ 'name' => 'Санкт-Петербург', 'type' => '' ],
					'settlement'  => [ 'name' => $label, 'type' => '' ],
					'label'       => $label,
					'raw'         => [ 'big' => str_repeat( 'x', 50 ) ],
				],
				$override
			);
		}

		private function city( string $key, string $label = 'Город', array $override = [] ): Location_Record {
			return Location_Record::from_array( $this->city_data( $key, $label, $override ) );
		}

		// ---- the mode -------------------------------------------------------------------------------------

		/** @return array<string, array{0: mixed, 1: string}> */
		public function modes(): array {
			return [
				'include'        => [ 'include', 'include' ],
				'exclude'        => [ 'exclude', 'exclude' ],
				'empty is off'   => [ '', '' ],
				'unknown is off' => [ 'always', '' ],
				'null is off'    => [ null, '' ],
				'array is off'   => [ [ 'include' ], '' ],
				'padded'         => [ ' include ', 'include' ],
			];
		}

		/**
		 * @dataProvider modes
		 * @param mixed  $raw      stored mode.
		 * @param string $expected clamped mode.
		 */
		public function test_a_mode_is_clamped_to_a_known_one( $raw, string $expected ): void {
			$this->assertSame( $expected, City_Limit::normalize_mode( $raw ) );
		}

		public function test_the_mode_labels_are_the_merchant_facing_words(): void {
			$this->assertSame(
				[ '', 'include', 'exclude' ],
				array_keys( City_Limit::modes() )
			);
			$this->assertSame( 'Доступен только в городах', City_Limit::modes()['include'] );
			$this->assertSame( 'Недоступен в городах', City_Limit::modes()['exclude'] );
		}

		// ---- the stored list --------------------------------------------------------------------------------

		public function test_cities_round_trip_whole_but_without_the_providers_payload(): void {
			$stored = City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] );

			$this->assertStringContainsString( 'Пушкин', $stored, 'unicode stays readable: a classic page strips slashes from what it posts' );
			$this->assertStringNotContainsString( '\\u', $stored );
			$this->assertStringNotContainsString( 'xxxx', $stored, 'the opaque `raw` is never stored' );

			$back = City_Limit::decode( $stored );

			$this->assertCount( 1, $back );
			$this->assertSame( 'test-cdek:394', $back[0]->key() );
			$this->assertSame( 'test-cdek', $back[0]->provider_id() );
			$this->assertSame( 'Санкт-Петербург', $back[0]->region()['name'] );
			$this->assertNull( $back[0]->raw() );
		}

		public function test_an_empty_or_broken_store_is_an_empty_list(): void {
			foreach ( [ '', '[]', '   ', 'not json', '{"a":1}', null, 5, false ] as $raw ) {
				$this->assertSame( [], City_Limit::decode( $raw ), var_export( $raw, true ) );
			}
		}

		public function test_a_malformed_entry_a_repeat_and_a_non_settlement_are_skipped(): void {
			$list = [
				$this->city_data( 'test-cdek:1', 'Один' ),
				'not a record',
				[ 'key' => 'broken' ],
				$this->city_data( 'test-cdek:1', 'Один снова' ),
				$this->city_data( 'test-cdek:r82', 'Регион', [ 'level' => 'region' ] ),
				$this->city_data( 'test-cdek:2', 'Два' ),
			];

			$cities = City_Limit::decode( json_encode( $list ) );

			$this->assertSame( [ 'test-cdek:1', 'test-cdek:2' ], array_map( static fn( $c ) => $c->key(), $cities ) );
			$this->assertSame( 'Один', $cities[0]->label(), 'the first of two repeats wins' );
		}

		public function test_a_list_is_cut_at_the_cap(): void {
			$list = [];

			for ( $i = 1; $i <= City_Limit::MAX_CITIES + 25; $i++ ) {
				$list[] = $this->city_data( 'test-cdek:' . $i, 'Город ' . $i );
			}

			$this->assertCount( City_Limit::MAX_CITIES, City_Limit::decode( json_encode( $list ) ) );
		}

		// ---- the ownership split ----------------------------------------------------------------------------

		public function test_cities_of_another_provider_are_stale(): void {
			$service        = new City_Limit_Test_Service();
			$service->owner = 'test-cdek';

			$parts = City_Limit::partition(
				[ $this->city( 'test-cdek:1' ), $this->city( 'dadata:abc' ) ],
				$service
			);

			$this->assertSame( [ 'test-cdek:1' ], array_map( static fn( $c ) => $c->key(), $parts['current'] ) );
			$this->assertSame( [ 'dadata:abc' ], array_map( static fn( $c ) => $c->key(), $parts['stale'] ) );
		}

		public function test_a_level_nobody_serves_makes_every_city_stale(): void {
			$service        = new City_Limit_Test_Service();
			$service->owner = null;

			$parts = City_Limit::partition( [ $this->city( 'test-cdek:1' ) ], $service );

			$this->assertSame( [], $parts['current'] );
			$this->assertCount( 1, $parts['stale'] );
		}

		// ---- the decision -----------------------------------------------------------------------------------

		public function test_only_these_cities_admits_a_listed_customer_and_refuses_the_rest(): void {
			$listed = [ $this->city( 'test-cdek:394', 'Пушкин' ) ];

			$this->assertTrue( City_Limit::allows( 'include', $listed, $this->city( 'test-cdek:394', 'Пушкин' ) ) );
			$this->assertFalse( City_Limit::allows( 'include', $listed, $this->city( 'test-cdek:44', 'Москва' ) ) );
		}

		public function test_not_in_these_cities_refuses_a_listed_customer_and_admits_the_rest(): void {
			$listed = [ $this->city( 'test-cdek:394', 'Пушкин' ) ];

			$this->assertFalse( City_Limit::allows( 'exclude', $listed, $this->city( 'test-cdek:394', 'Пушкин' ) ) );
			$this->assertTrue( City_Limit::allows( 'exclude', $listed, $this->city( 'test-cdek:44', 'Москва' ) ) );
		}

		public function test_a_customer_inside_a_listed_city_by_ancestry_counts_as_listed(): void {
			// `is_within()` is the contract: a record answers for its published ancestors, not only its own key.
			$parent   = $this->city( 'test-cdek:50', 'Город-центр' );
			$district = $this->city( 'test-cdek:51', 'Район', [ 'ancestors' => [ 'test-cdek:50' ] ] );

			$this->assertTrue( City_Limit::allows( 'include', [ $parent ], $district ) );
			$this->assertFalse( City_Limit::allows( 'exclude', [ $parent ], $district ) );
		}

		public function test_no_limit_means_available(): void {
			$this->assertTrue( City_Limit::allows( '', [ $this->city( 'test-cdek:1' ) ], $this->city( 'test-cdek:2' ) ) );
			$this->assertTrue( City_Limit::allows( 'nonsense', [ $this->city( 'test-cdek:1' ) ], $this->city( 'test-cdek:2' ) ) );
		}

		public function test_no_customer_record_yet_means_available_in_both_modes(): void {
			$listed = [ $this->city( 'test-cdek:1' ) ];

			$this->assertTrue( City_Limit::allows( 'include', $listed, null ), 'D1: never hide shipping for lack of data' );
			$this->assertTrue( City_Limit::allows( 'exclude', $listed, null ) );
		}

		public function test_an_empty_list_means_available_in_both_modes(): void {
			$customer = $this->city( 'test-cdek:2' );

			$this->assertTrue( City_Limit::allows( 'include', [], $customer ), 'an «only these» list of nothing must not hide the method from everyone' );
			$this->assertTrue( City_Limit::allows( 'exclude', [], $customer ) );
		}

		// ---- permits(): the decision wired to the customer ---------------------------------------------------

		public function test_permits_reads_the_customer_for_the_package_country(): void {
			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:44', 'Москва' );
			$stored            = City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] );

			$this->assertFalse( City_Limit::permits( 'include', $stored, $service, 'ru' ) );
			$this->assertSame( 'RU', $service->asked_country, 'the destination country is the authority, normalized' );

			$this->assertTrue( City_Limit::permits( 'exclude', $stored, $service, '' ) );
			$this->assertNull( $service->asked_country, 'no country given → the ambient one' );
		}

		public function test_permits_ignores_stale_cities_and_so_never_hides_the_method_for_them(): void {
			$service           = new City_Limit_Test_Service();
			$service->owner    = 'test-cdek';
			$service->customer = $this->city( 'test-cdek:44', 'Москва' );
			$stale_only        = City_Limit::encode( [ $this->city( 'dadata:abc', 'Пушкин' ) ] );

			$this->assertTrue( City_Limit::permits( 'include', $stale_only, $service ), 'D2: stale cities are ignored' );

			$mixed = City_Limit::encode( [ $this->city( 'dadata:abc', 'Пушкин' ), $this->city( 'test-cdek:394', 'Пушкин' ) ] );

			$this->assertFalse( City_Limit::permits( 'include', $mixed, $service ), 'the fresh one still counts' );
		}

		public function test_permits_without_a_mode_asks_nobody(): void {
			$service         = new City_Limit_Test_Service();
			$service->throws = true;

			$this->assertTrue( City_Limit::permits( '', 'whatever', $service ) );
		}

		public function test_a_location_layer_that_fails_answers_available(): void {
			$service         = new City_Limit_Test_Service();
			$service->throws = true;

			$this->assertTrue( City_Limit::permits( 'include', City_Limit::encode( [ $this->city( 'test-cdek:1' ) ] ), $service ) );
		}

		public function test_the_stores_guessed_default_locality_is_not_the_customers_city(): void {
			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:44', 'Москва' );
			$service->implicit = true;

			$pushkin = City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] );
			$moscow  = City_Limit::encode( [ $this->city( 'test-cdek:44', 'Москва' ) ] );

			$this->assertTrue( City_Limit::permits( 'include', $pushkin, $service ), 'only-Pushkin must not hide the method from a default that nobody picked' );
			$this->assertTrue( City_Limit::permits( 'exclude', $moscow, $service ), 'not-in-Moscow must not hide it either' );

			$service->implicit = false;
			$this->assertFalse( City_Limit::permits( 'include', $pushkin, $service ), 'the same record, once the customer picked it, counts' );
			$this->assertFalse( City_Limit::permits( 'exclude', $moscow, $service ) );
		}

		public function test_cities_outside_the_zone_are_ignored_at_checkout_exactly_as_the_form_says(): void {
			$service             = new City_Limit_Test_Service();
			$service->customer   = $this->city( 'test-cdek:600', 'Омск' );
			$service->state_code = 'САНКТ-ПЕТЕРБУРГ';
			$stored              = City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] );

			$was = City_Limit::scope_from_locations( [ [ 'state', 'RU:САНКТ-ПЕТЕРБУРГ' ] ] );
			$now = City_Limit::scope_from_locations( [ [ 'state', 'RU:ОМСКАЯ ОБЛАСТЬ' ] ] );

			$this->assertFalse( City_Limit::permits( 'include', $stored, $service, 'RU', $was ), 'in the zone it was made for, Omsk is not on the list' );
			$this->assertTrue( City_Limit::permits( 'include', $stored, $service, 'RU', $now ), 'the zone moved to Omsk: Pushkin no longer counts, the list is empty, the method is available' );
		}

		public function test_a_city_of_a_country_the_zone_does_not_reach_is_ignored(): void {
			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:44', 'Москва' );
			$by                = City_Limit::encode( [ $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] ) ] );

			$this->assertTrue( City_Limit::permits( 'include', $by, $service, 'RU', City_Limit::scope_from_locations( [ [ 'country', 'RU' ] ] ) ) );
			$this->assertFalse( City_Limit::permits( 'include', $by, $service, 'RU', [] ), 'no zone scope given: nothing is dropped' );
		}

		// ---- the zone's regions -----------------------------------------------------------------------------

		public function test_a_zone_listing_only_a_country_has_no_region_restriction(): void {
			$this->assertSame(
				[ 'country' => 'RU', 'countries' => [ 'RU' ], 'states' => [] ],
				City_Limit::scope_from_locations( [ [ 'country', 'RU' ] ] )
			);
		}

		public function test_a_zone_listing_states_restricts_to_them(): void {
			$this->assertSame(
				[
					'country'   => 'RU',
					'countries' => [ 'RU' ],
					'states'    => [ 'RU' => [ 'ОМСКАЯ ОБЛАСТЬ', 'САНКТ-ПЕТЕРБУРГ' ] ],
				],
				City_Limit::scope_from_locations(
					[ [ 'state', 'RU:ОМСКАЯ ОБЛАСТЬ' ], [ 'state', 'RU:САНКТ-ПЕТЕРБУРГ' ] ]
				)
			);
		}

		public function test_a_country_listed_whole_wins_over_its_states(): void {
			$scope = City_Limit::scope_from_locations( [ [ 'country', 'RU' ], [ 'state', 'RU:ОМСКАЯ ОБЛАСТЬ' ], [ 'state', 'KZ:ALM' ] ] );

			$this->assertSame( [ 'KZ' => [ 'ALM' ] ], $scope['states'] );
			$this->assertSame( [ 'RU', 'KZ' ], $scope['countries'], 'RU stays reachable: the zone lists it whole' );
			$this->assertSame( 'RU', $scope['country'], 'the first of the zone, in zone order' );
		}

		public function test_a_zone_reaching_several_countries_keeps_every_one_of_them(): void {
			$scope = City_Limit::scope_from_locations( [ [ 'country', 'RU' ], [ 'country', 'BY' ], [ 'state', 'KZ:ALM' ], [ 'state', 'KZ:AST' ], [ 'country', 'ru' ] ] );

			$this->assertSame( [ 'RU', 'BY', 'KZ' ], $scope['countries'] );
			$this->assertSame( [ 'KZ' => [ 'ALM', 'AST' ] ], $scope['states'] );
		}

		// ---- continents (fix round 2) -----------------------------------------------------------------------

		/** A stand-in for WooCommerce's continent table, so the expansion is the production code's own. */
		private function europe(): void {
			City_Limit::use_continents_for_tests( [ 'EU' => [ 'BY', 'DE', 'FR' ] ] );
		}

		public function test_a_continent_next_to_a_country_reaches_every_country_of_both(): void {
			$this->europe();

			$scope = City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] );

			$this->assertSame( [ 'BY', 'DE', 'FR', 'KZ' ], $scope['countries'], 'WooCommerce matches continent OR country: Belarus is reached through Europe' );
			$this->assertSame( 'BY', $scope['country'] );
			$this->assertSame( [], $scope['states'] );
		}

		public function test_a_continent_overrides_a_narrower_state_row_of_a_country_it_covers(): void {
			$this->europe();

			$scope = City_Limit::scope_from_locations( [ [ 'state', 'BY:MI' ], [ 'continent', 'EU' ], [ 'state', 'KZ:ALM' ] ] );

			$this->assertSame( [ 'KZ' => [ 'ALM' ] ], $scope['states'], 'BY is covered whole by Europe, whatever its state row says; KZ is not' );
			$this->assertSame( [ 'BY', 'DE', 'FR', 'KZ' ], $scope['countries'] );
		}

		public function test_a_continent_that_cannot_be_expanded_never_leaves_a_partial_country_list(): void {
			// no WooCommerce in the unit process and no table put in place: the continent is unknown
			$this->assertNull( City_Limit::continent_countries( 'EU' ) );

			$scope = City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ], [ 'state', 'RU:MOW' ] ] );

			$this->assertSame( [ 'country' => '', 'countries' => [], 'states' => [] ], $scope, 'KZ alone would read as «the zone is KZ only»' );

			City_Limit::use_continents_for_tests( [ 'XX' => [ 'DE' ] ] );
			$this->assertSame( [ 'country' => '', 'countries' => [], 'states' => [] ], City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] ), 'a continent the table does not know' );
		}

		public function test_a_city_reached_through_a_continent_is_in_a_mixed_zone(): void {
			$this->europe();

			$scope = City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] );
			$minsk = $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] );

			$this->assertTrue( City_Limit::in_zone( $minsk, $scope['states'], new City_Limit_Test_Service(), $scope['countries'] ) );
		}

		public function test_a_listed_city_in_a_continent_country_keeps_limiting_a_buyer_in_the_country_row(): void {
			$this->europe();

			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:700', 'Алматы', [ 'country' => 'KZ' ] );
			$stored            = City_Limit::encode( [ $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] ) ] );

			$mixed = City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] );

			// Minsk is reached through Europe, so it still counts: an Almaty buyer is not on an include-only Minsk list
			$this->assertFalse( City_Limit::permits( 'include', $stored, $service, 'KZ', $mixed ) );

			// …and when Europe cannot be expanded nothing is narrowed either
			City_Limit::use_continents_for_tests( null );
			$unknown = City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] );
			$this->assertFalse( City_Limit::permits( 'include', $stored, $service, 'KZ', $unknown ) );
		}

		public function test_the_rest_of_the_world_zone_and_junk_have_no_scope(): void {
			$empty = [ 'country' => '', 'countries' => [], 'states' => [] ];

			$this->assertSame( $empty, City_Limit::scope_from_locations( [] ) );
			$this->assertSame( $empty, City_Limit::scope_from_locations( [ [ 'continent', 'EU' ], [ 'postcode', '1000...2000' ], [ 'state', 'broken' ], [ 'state', 'RU:' ] ] ) );
		}

		public function test_without_woocommerce_the_zone_scope_is_empty(): void {
			$this->assertSame( [ 'country' => '', 'countries' => [], 'states' => [] ], City_Limit::zone_scope( 262 ) );
			$this->assertSame( [ 'country' => '', 'countries' => [], 'states' => [] ], City_Limit::zone_scope( 0 ) );
		}

		public function test_a_record_in_a_zones_regions_is_kept_and_one_outside_is_not(): void {
			$service             = new City_Limit_Test_Service();
			$service->state_code = 'САНКТ-ПЕТЕРБУРГ';
			$record              = $this->city( 'test-cdek:394', 'Пушкин' );

			$this->assertTrue( City_Limit::in_zone( $record, [ 'RU' => [ 'САНКТ-ПЕТЕРБУРГ' ] ], $service ) );
			$this->assertFalse( City_Limit::in_zone( $record, [ 'RU' => [ 'ОМСКАЯ ОБЛАСТЬ' ] ], $service ) );
		}

		public function test_a_city_of_a_country_outside_the_zones_countries_is_not_in_the_zone(): void {
			$service = new City_Limit_Test_Service();

			$this->assertFalse( City_Limit::in_zone( $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] ), [], $service, [ 'RU' ] ) );
			$this->assertTrue( City_Limit::in_zone( $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] ), [], $service, [ 'RU', 'BY' ] ) );
			$this->assertTrue( City_Limit::in_zone( $this->city( 'test-cdek:9', 'Минск', [ 'country' => 'BY' ] ), [], $service, [] ) );
		}

		public function test_a_doubt_never_drops_a_city(): void {
			$service = new City_Limit_Test_Service();
			$record  = $this->city( 'test-cdek:394', 'Пушкин' );

			$service->state_code = null;
			$this->assertTrue( City_Limit::in_zone( $record, [ 'RU' => [ 'ОМСКАЯ ОБЛАСТЬ' ] ], $service ), 'no state for the record: cannot tell' );
			$this->assertTrue( City_Limit::in_zone( $record, [], $service ), 'no region restriction' );
			$this->assertTrue( City_Limit::in_zone( $record, [ 'KZ' => [ 'ALM' ] ], $service ), 'the zone says nothing about this country' );
		}
	}
}
