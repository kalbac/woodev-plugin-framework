<?php
/**
 * Unit tests for the rig's CDEK fixture provider (issue #343).
 *
 * Covers the two things about it that are decided in OUR code rather than by the carrier,
 * and are therefore ours to keep from regressing:
 *
 * 1. The SHAPE it declares — region and settlement, never `address`, and the `list`
 *    capability. That shape is the whole reason the fixture exists (it is what makes #337's
 *    "no address suggestions ⇒ no lock" rule observable against a live provider), and it is
 *    exactly the kind of thing a well-meaning later edit widens by accident.
 * 2. The ONE derived rule in it — splitting CDEK's `full_name` composite into display
 *    components. The strings below are verbatim captures from the live test contour
 *    (16.08.2026), all three shapes it actually produces.
 *
 * Nothing here touches the network: both are answerable without a token, which is why the
 * parse was extracted as a pure static in the first place.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Exception;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/_fixtures/woodev-test-shipping-method/class-test-cdek-location-provider.php';

/**
 * Class CdekFixtureProviderTest
 */
class CdekFixtureProviderTest extends TestCase {

	/**
	 * The declared levels are region and settlement — and `address` is NOT among them.
	 *
	 * Asserted as an explicit absence, not merely as an expected pair: this is the
	 * property #343's scenario A and #337's lock rule both hang off, and it deserves to
	 * fail with a message that says which one broke.
	 */
	public function test_it_declares_region_and_settlement_but_never_address(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame(
			[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
			$provider->get_suggest_levels()
		);
		$this->assertNotContains(
			Location_Record::LEVEL_ADDRESS,
			$provider->get_suggest_levels(),
			'CDEK has no street data — a fixture claiming the address level would stop being the live case #343 needs.'
		);
	}

	/**
	 * The per-country answer does not widen the unnarrowed one — CDEK's dictionary has no
	 * street data anywhere, so no country may report an address level.
	 */
	public function test_no_country_reports_an_address_level(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		foreach ( $provider->get_countries() as $country ) {
			$this->assertNotContains(
				Location_Record::LEVEL_ADDRESS,
				$provider->get_suggest_levels( $country ),
				sprintf( 'Country %s must not report an address level.', $country )
			);
		}
	}

	/**
	 * The `list` and `resolve_key` capabilities are declared (by overriding
	 * `list_localities()`/`resolve_key()`), and the two capabilities this provider
	 * does NOT implement (`locate`, `normalize`) are not.
	 */
	public function test_it_declares_only_the_list_and_resolve_key_capabilities(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame(
			[ Location_Provider::CAPABILITY_LIST, Location_Provider::CAPABILITY_RESOLVE_KEY ],
			$provider->get_capabilities()
		);
	}

	/**
	 * ZERO declared settings fields (issue #375) — the reworked contract: CDEK's
	 * Client ID/Secret authenticate every CDEK API call, not only the location
	 * dictionary, so they live in the carrier's own settings
	 * ({@see \Woodev_Test_Cdek_Integration}), not here. This is the property the
	 * operator's #375 target table depends on: with zero fields declared, NOTHING
	 * from this provider is merged onto the shared "Локация" settings surface.
	 */
	public function test_it_declares_zero_settings_fields(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame( [], $provider->get_settings_fields() );
	}

	/**
	 * A three-part `full_name`: settlement, region, country.
	 */
	public function test_it_splits_a_settlement_with_a_region(): void {
		$this->assertSame(
			[
				'settlement' => 'Московский',
				'district'   => '',
				'region'     => 'Московская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Московский, Московская область, Россия' )
		);
	}

	/**
	 * A four-part `full_name`: settlement, district, region, country.
	 */
	public function test_it_splits_a_settlement_with_a_district(): void {
		$this->assertSame(
			[
				'settlement' => 'Московская',
				'district'   => 'Афанасьевский район',
				'region'     => 'Кировская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Московская, Афанасьевский район, Кировская область, Россия' )
		);
	}

	/**
	 * A FEDERAL CITY carries no region part at all — the parse must leave the region empty
	 * rather than promoting the country into it. (Recovering the region for this case is a
	 * separate, dictionary-confirmed step in the provider itself, deliberately not part of
	 * the parse.)
	 */
	public function test_it_leaves_a_federal_city_without_a_region(): void {
		$this->assertSame(
			[
				'settlement' => 'Москва',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Москва, Россия' )
		);
	}

	/**
	 * Degenerate input never throws and never invents a component: an empty string yields
	 * an empty settlement, and a single part is a settlement with nothing above it.
	 */
	public function test_it_survives_degenerate_input(): void {
		$this->assertSame(
			[
				'settlement' => '',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( '' )
		);

		$this->assertSame(
			[
				'settlement' => 'Одинокое',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Одинокое' )
		);
	}

	/**
	 * Whitespace and empty segments are cleaned rather than carried into a component —
	 * CDEK's own strings are tidy, but a component with a stray leading space is exactly
	 * the kind of thing that later reads as a different locality name.
	 */
	public function test_it_trims_and_drops_empty_segments(): void {
		$this->assertSame(
			[
				'settlement' => 'Пушкино',
				'district'   => 'Пушкинский городской округ',
				'region'     => 'Московская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( ' Пушкино ,, Пушкинский городской округ ,  Московская область , Россия ' )
		);
	}

	// -------------------------------------------------------------------------
	// Issue #405 (critic follow-up): a `200 OK` whose body cannot be understood
	// — malformed JSON, or valid JSON of the wrong shape — is a FAILED request,
	// not an empty one. `request()` must throw, never degrade to `[]`, in both
	// cases. Exercised through `suggest()` at the SETTLEMENT level with no
	// parent scope: `suggest_settlements()` calls `request()` before it ever
	// touches the region dictionary, so a cached transient token is all the
	// network stubbing this needs — no Woodev_Test_Cdek_Integration/
	// Woodev_Test_Shipping_Method_Plugin bootstrap required.
	// -------------------------------------------------------------------------

	/**
	 * The path and query args {@see self::stub_settlement_suggest_transport()}'s
	 * `add_query_arg` stub most recently captured — `[ $path, $params ]`, where
	 * `$path` is CDEK's API base with the endpoint path appended (e.g.
	 * `https://api.edu.cdek.ru/v2/location/cities`) and `$params` is the
	 * rawurlencode()'d query args {@see \Woodev_Test_Cdek_Location_Provider::request()}
	 * built. `null` until a request is made.
	 *
	 * @var array{0: string, 1: array<string, mixed>}|null
	 */
	private ?array $last_request = null;

	/**
	 * Stubs a cached OAuth token (bypassing credential()/is_configured() entirely —
	 * {@see \Woodev_Test_Cdek_Location_Provider::token()} returns it straight from
	 * {@see get_transient()} before ever touching the carrier's own Integration
	 * settings) and a GET response carrying `$body` as its raw response body.
	 *
	 * Captures the actual `add_query_arg( $params, $path )` call into
	 * {@see self::$last_request} — a stub that only ever returns a FIXED URL
	 * regardless of what was asked would let a test pass even if the caller hit
	 * the wrong CDEK endpoint or sent the wrong filter param (critic finding,
	 * round 2), so every resolve_key() test below asserts against this.
	 *
	 * @param string $body Raw (un-decoded) HTTP response body.
	 *
	 * @return void
	 */
	private function stub_settlement_suggest_transport( string $body ): void {
		$this->last_request = null;

		Functions\when( 'get_transient' )->justReturn( 'fake-cached-token' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $params, $path ) {
				$this->last_request = [ $path, $params ];

				return 'https://api.edu.cdek.ru/v2/location/suggest/cities';
			}
		);
		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * Asserts the endpoint {@see self::$last_request} captured matches
	 * `$expected_path`, and that its query args contain `$expected_params`
	 * (exact key/value match, rawurlencode()'d the same way `request()` encodes
	 * them — every value here is a plain digit string, so encoding is a no-op).
	 *
	 * @param string                $expected_path   Full expected URL.
	 * @param array<string, string> $expected_params Expected (already-encoded) query args.
	 *
	 * @return void
	 */
	private function assert_last_request( string $expected_path, array $expected_params ): void {
		$this->assertNotNull( $this->last_request, 'no request was captured' );
		$this->assertSame( $expected_path, $this->last_request[0] );
		$this->assertSame( $expected_params, $this->last_request[1] );
	}

	/**
	 * Stubs a cached OAuth token (same shortcut as
	 * {@see self::stub_settlement_suggest_transport()}) plus a
	 * `GET /location/regions` response that varies by `country_codes` — for
	 * {@see \Woodev_Test_Cdek_Location_Provider::resolve_region()} (issue
	 * #553), which now asks about EVERY supported country in turn rather
	 * than trusting a single-row `region_code` filter the live test contour
	 * does not actually honour (measured, see that method's own docblock).
	 *
	 * `$rows_by_country` is keyed by ISO-3166 alpha-2; a country not present
	 * answers `[]` (CDEK's own "no matches" shape), matching `regions()`'s
	 * own request params exactly ({@see Woodev_Test_Cdek_Location_Provider::regions()}).
	 * Captures the LAST request into {@see self::$last_request}, same as
	 * {@see self::stub_settlement_suggest_transport()}.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows_by_country Raw `/location/regions` rows, keyed by country.
	 *
	 * @return void
	 */
	private function stub_region_dictionary_transport( array $rows_by_country ): void {
		$this->last_request = null;

		Functions\when( 'get_transient' )->justReturn( 'fake-cached-token' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias(
			function ( $params, $path ) {
				$this->last_request = [ $path, $params ];

				return [ $path, $params ];
			}
		);
		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $request ) {
				return [ 'request' => $request ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) use ( $rows_by_country ) {
				[ , $params ] = $response['request'];
				$country = strtoupper( (string) ( $params['country_codes'] ?? '' ) );

				return (string) json_encode( $rows_by_country[ $country ] ?? [] );
			}
		);
	}

	/**
	 * A `200 OK` body that is not JSON at all — `json_decode()` returns `null`,
	 * which is not an array.
	 */
	public function test_suggest_throws_on_a_200_response_with_a_malformed_json_body(): void {
		$this->stub_settlement_suggest_transport( '{not-json' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( Location_Provider_Exception::class );

		$provider->suggest( 'Мос', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );
	}

	/**
	 * A `200 OK` body that IS valid JSON, but not the documented list shape — a bare
	 * JSON string decodes to a PHP string, not an array.
	 */
	public function test_suggest_throws_on_a_200_response_with_valid_json_of_the_wrong_shape(): void {
		$this->stub_settlement_suggest_transport( '"just a string"' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( Location_Provider_Exception::class );

		$provider->suggest( 'Мос', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );
	}

	/**
	 * The counterpart proving the fix did not overcorrect: a well-shaped `200` body
	 * (a real JSON array, even an empty one — CDEK's own "no matches" answer) must
	 * still return `[]`, never throw. This is the "succeeded with zero matches" state
	 * #405 requires staying distinguishable from the two failures above.
	 */
	public function test_suggest_returns_empty_for_a_well_shaped_zero_match_response(): void {
		$this->stub_settlement_suggest_transport( '[]' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame(
			[],
			$provider->suggest( 'Заброшенный', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) )
		);
	}

	// -------------------------------------------------------------------------
	// resolve_key() — popular-settlements spec D4. Uses the same cached-token
	// transport shortcut as the #405 block above ({@see self::stub_settlement_suggest_transport()})
	// so none of these need the carrier's own Integration/plugin bootstrap.
	// -------------------------------------------------------------------------

	public function test_resolve_key_resolves_a_settlement_by_code(): void {
		$this->stub_settlement_suggest_transport(
			(string) json_encode(
				[
					[
						'code'         => 44,
						'city'         => 'Москва',
						'country_code' => 'RU',
						'region'       => 'Москва',
						'region_code'  => 81,
					],
				]
			)
		);

		$provider = new \Woodev_Test_Cdek_Location_Provider();
		$record   = $provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44' );

		// Tightened per round-2 critic finding: the previous stub returned the
		// same fixed URL/body no matter what request() actually sent, so this
		// test would have passed even against the WRONG endpoint or the WRONG
		// filter param.
		$this->assert_last_request(
			'https://api.edu.cdek.ru/v2/location/cities',
			[ 'code' => '44' ]
		);

		$this->assertNotNull( $record );
		$this->assertSame( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44', $record->key() );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $record->level() );
		$this->assertSame( 'RU', $record->country() );
		$this->assertSame( [ 'name' => 'Москва', 'type' => '' ], $record->settlement() );
	}

	public function test_resolve_key_resolves_a_region_by_code(): void {
		$this->stub_region_dictionary_transport(
			[
				'RU' => [
					[
						'region_code'  => 81,
						'region'       => 'Москва',
						'country_code' => 'RU',
					],
				],
			]
		);

		$provider = new \Woodev_Test_Cdek_Location_Provider();
		$record   = $provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r81' );

		$this->assert_last_request(
			'https://api.edu.cdek.ru/v2/location/regions',
			[ 'country_codes' => 'RU', 'size' => '1000' ]
		);

		$this->assertNotNull( $record );
		$this->assertSame( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r81', $record->key() );
		$this->assertSame( Location_Record::LEVEL_REGION, $record->level() );
		$this->assertSame( 'RU', $record->country() );
		$this->assertSame( [ 'name' => 'Москва', 'type' => '' ], $record->region() );
	}

	public function test_resolve_key_returns_null_when_cdek_returns_no_rows(): void {
		$this->stub_settlement_suggest_transport( '[]' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertNull( $provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':999999' ) );
	}

	public function test_resolve_key_region_returns_null_when_cdek_returns_no_rows(): void {
		$this->stub_settlement_suggest_transport( '[]' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertNull( $provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r999999' ) );
	}

	public function test_resolve_key_rejects_a_key_belonging_to_another_provider(): void {
		Functions\expect( 'wp_safe_remote_get' )->never();

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( \InvalidArgumentException::class );
		$provider->resolve_key( 'dadata:some-fias-id' );
	}

	public function test_resolve_key_rejects_a_native_id_shape_this_provider_never_produces(): void {
		Functions\expect( 'wp_safe_remote_get' )->never();

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( \InvalidArgumentException::class );
		$provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':not-a-code' );
	}

	public function test_resolve_key_throws_rather_than_returns_null_on_a_malformed_response(): void {
		$this->stub_settlement_suggest_transport( '{not-json' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( Location_Provider_Exception::class );
		$provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44' );
	}

	// ---- HIGH 1 (round 2 critic): a non-empty but UNMAPPABLE row must not
	// read as "gone" either — a `200` we cannot map is our own mapping failing,
	// not CDEK confirming the settlement/region no longer exists.

	public function test_resolve_key_throws_rather_than_returns_null_for_an_unmappable_settlement_row(): void {
		$this->stub_settlement_suggest_transport(
			// A row present, but missing the fields record_from_city_row() requires
			// (`code`/`country_code`) — CDEK answered, just not usably.
			(string) json_encode( [ [ 'city' => 'Москва' ] ] )
		);

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( Location_Provider_Exception::class );
		$provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44' );
	}

	/**
	 * Superseded by the #553 rewrite of `resolve_region()`: it now searches
	 * the SAME per-country dictionary {@see \Woodev_Test_Cdek_Location_Provider::regions()}
	 * already builds for `match_regions()`/`list_localities()` — which
	 * deliberately, per that method's own docblock, skips a row missing
	 * `region_code`/`region` while enumerating many, rather than trusting a
	 * single untrustworthy row at face value. The failure mode the OLD
	 * "throw on an unmappable row" test targeted (HIGH 1, round 2 of #405)
	 * cannot occur any more for regions: there is no longer a SINGLE row
	 * being trusted — a malformed dictionary row is now excluded from the
	 * map exactly like `list_localities()`/`match_regions()` already exclude
	 * one, indistinguishable from "not present in this country".
	 */
	public function test_resolve_key_region_skips_a_malformed_row_and_keeps_searching(): void {
		$this->stub_region_dictionary_transport(
			[
				// Missing `region`/`country_code` — regions() requires both
				// and silently skips a row missing either.
				'RU' => [ [ 'region_code' => 81 ] ],
			]
		);

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertNull(
			$provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r81' ),
			'a malformed dictionary row degrades to "not found", the same as any other row regions() cannot map'
		);
	}

	// -------------------------------------------------------------------------
	// #358 — region_code_from_scope() reports a narrowing verdict on the scope
	// it is handed. Shared by suggest_settlements() AND list_localities(), so
	// exercising it through one covers both call sites.
	// -------------------------------------------------------------------------

	/**
	 * Stubs BOTH `/location/suggest/cities` (or `/location/cities`) and
	 * `/location/regions` behind one cached-token transport — the own-provider-key
	 * branch of `region_code_from_scope()` never touches the network, but the
	 * components-name branch calls `region_code_for_name()` -> `regions()`, a
	 * SEPARATE endpoint from whichever settlement call the provider method itself
	 * makes. Routes on the request URL, same shape as
	 * {@see self::stub_region_dictionary_transport()}.
	 *
	 * @param array<int, mixed>                     $settlement_rows Rows for the
	 *                                                                non-`/location/regions` endpoint.
	 * @param array<string, array<int, mixed>>      $region_rows     `/location/regions` rows, keyed by country.
	 *
	 * @return void
	 */
	private function stub_narrowing_transport( array $settlement_rows, array $region_rows = [] ): void {
		Functions\when( 'get_transient' )->justReturn( 'fake-cached-token' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $params, $path ) {
				return [ $path, $params ];
			}
		);
		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $request ) {
				return [ 'request' => $request ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) use ( $settlement_rows, $region_rows ) {
				[ $path, $params ] = $response['request'];

				if ( false !== strpos( $path, '/location/regions' ) ) {
					$country = strtoupper( (string) ( $params['country_codes'] ?? '' ) );

					return (string) json_encode( $region_rows[ $country ] ?? [] );
				}

				return (string) json_encode( $settlement_rows );
			}
		);
	}

	/**
	 * @return \Woodev\Framework\Shipping\Location\Location_Record Own-provider region
	 *         parent, `region_code` 81 ("Москва") — the shape a real prior
	 *         `list_localities()`/`suggest()` region call would have returned.
	 */
	private function own_region_parent(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r81',
				'provider_id' => \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID,
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => 'RU',
				'region'      => [ 'name' => 'Москва' ],
			]
		);
	}

	/**
	 * @return \Woodev\Framework\Shipping\Location\Location_Record A region record from
	 *         ANOTHER provider — the D15 cross-provider handover shape (gotcha
	 *         `a-cross-provider-within-is-handed-over-as-components`).
	 */
	private function foreign_region_parent(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'dadata:0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => 'RU',
				'region'      => [ 'name' => 'Москва' ],
			]
		);
	}

	public function test_suggest_settlements_reports_exact_narrowing_for_an_own_provider_region_parent(): void {
		$this->stub_narrowing_transport( [] );

		$scope = Location_Scope::within( $this->own_region_parent(), Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_EXACT, $scope->narrowing() );
	}

	/**
	 * PIN (#358, critic follow-up): `exact` must not just be the REPORTED verdict — the
	 * filter it describes must actually have run. Upstream answers TWO rows, one inside
	 * the requested region, one outside it; only the inside one may survive. Without
	 * this, a broken ancestor-key guard at {@see \Woodev_Test_Cdek_Location_Provider::suggest_settlements()}'s
	 * own `in_array(... $record->ancestors() ...)` check (region_code_from_scope()
	 * itself untouched) would still report `exact` while quietly answering country-wide —
	 * the exact bug this whole issue exists to make visible, just moved one level down.
	 */
	public function test_suggest_settlements_with_exact_narrowing_actually_filters_to_the_region(): void {
		$this->stub_narrowing_transport(
			[
				[ 'code' => 111, 'full_name' => 'Царицыно, Москва, Россия', 'country_code' => 'RU' ],
				[ 'code' => 222, 'full_name' => 'Пушкино, Московская область, Россия', 'country_code' => 'RU' ],
			],
			[ 'RU' => [ [ 'region_code' => 81, 'region' => 'Москва', 'country_code' => 'RU' ] ] ]
		);

		$scope = Location_Scope::within( $this->own_region_parent(), Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		$records = ( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_EXACT, $scope->narrowing() );
		$this->assertCount( 1, $records, 'the out-of-region row must be filtered out — an exact verdict with no actual filtering is the bug #358 exists to surface' );
		$this->assertSame( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':111', $records[0]->key(), 'must be the IN-region row, not the out-of-region one' );
	}

	public function test_suggest_settlements_reports_none_narrowing_for_a_foreign_provider_parent(): void {
		$this->stub_narrowing_transport( [] );

		$scope = Location_Scope::within( $this->foreign_region_parent(), Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_NONE, $scope->narrowing() );
	}

	public function test_suggest_settlements_reports_degraded_narrowing_for_a_components_parent_resolved_by_name(): void {
		$this->stub_narrowing_transport(
			[],
			[ 'RU' => [ [ 'region_code' => 81, 'region' => 'Москва', 'country_code' => 'RU' ] ] ]
		);

		$scope = Location_Scope::within_components( 'RU', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Москва' ] ] )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_DEGRADED, $scope->narrowing() );
	}

	public function test_suggest_settlements_reports_none_narrowing_when_the_components_name_is_unknown(): void {
		$this->stub_narrowing_transport( [] );

		$scope = Location_Scope::within_components( 'RU', Location_Record::LEVEL_SETTLEMENT, [ 'region' => [ 'name' => 'Неизвестная область' ] ] )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_NONE, $scope->narrowing() );
	}

	public function test_suggest_settlements_reports_nothing_when_the_scope_has_no_parent(): void {
		// No `_doing_it_wrong` expectation set: if region_code_from_scope() called
		// report_narrowing() here, Brain Monkey would fail this test on the
		// unexpected call — the absence of that expectation IS the assertion.
		$this->stub_narrowing_transport( [] );

		$scope = Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertSame( Location_Provider::NARROWING_UNREPORTED, $scope->narrowing() );
	}

	/**
	 * PIN (#358): a foreign/unresolvable parent must not gain a filter it never had —
	 * `suggest_settlements()` keeps answering COUNTRY-WIDE when `region_code_from_scope()`
	 * returns `null`, exactly as it did before this issue's change. Only the
	 * OBSERVABILITY of that fact changed (via `narrowing()`), never the behaviour —
	 * see this fixture's own `region_code_from_scope()` docblock, and the gotcha
	 * `within-applied-reports-the-scope-builder-not-the-provider`. "Fixing" this by
	 * making an unresolvable parent start filtering is exactly the regression this
	 * test exists to catch.
	 */
	public function test_suggest_settlements_still_answers_unnarrowed_when_narrowing_is_none(): void {
		$this->stub_narrowing_transport( [ [ 'code' => 999, 'country_code' => 'RU' ] ] );

		$scope = Location_Scope::within( $this->foreign_region_parent(), Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		$records = ( new \Woodev_Test_Cdek_Location_Provider() )->suggest( 'Мос', $scope );

		$this->assertCount( 1, $records, 'a foreign/unresolvable parent must not silently start filtering results' );
		$this->assertSame( Location_Provider::NARROWING_NONE, $scope->narrowing() );
	}

	public function test_list_localities_reports_exact_narrowing_for_an_own_provider_region_parent(): void {
		$this->stub_narrowing_transport(
			[ [ 'code' => 44, 'city' => 'Москва', 'country_code' => 'RU', 'region' => 'Москва', 'region_code' => 81 ] ]
		);

		$scope = Location_Scope::within( $this->own_region_parent(), Location_Record::LEVEL_SETTLEMENT )
			->for_provider( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID );

		( new \Woodev_Test_Cdek_Location_Provider() )->list_localities( $scope );

		$this->assertSame(
			Location_Provider::NARROWING_EXACT,
			$scope->narrowing(),
			'list_localities() shares region_code_from_scope() with suggest_settlements() — one change covers both'
		);
	}
}
