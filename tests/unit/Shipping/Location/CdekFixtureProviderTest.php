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
	 * Stubs a cached OAuth token (bypassing credential()/is_configured() entirely —
	 * {@see \Woodev_Test_Cdek_Location_Provider::token()} returns it straight from
	 * {@see get_transient()} before ever touching the carrier's own Integration
	 * settings) and a GET response carrying `$body` as its raw response body.
	 *
	 * @param string $body Raw (un-decoded) HTTP response body.
	 *
	 * @return void
	 */
	private function stub_settlement_suggest_transport( string $body ): void {
		Functions\when( 'get_transient' )->justReturn( 'fake-cached-token' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://api.edu.cdek.ru/v2/location/suggest/cities' );
		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
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

		$this->assertNotNull( $record );
		$this->assertSame( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44', $record->key() );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $record->level() );
		$this->assertSame( 'RU', $record->country() );
		$this->assertSame( [ 'name' => 'Москва', 'type' => '' ], $record->settlement() );
	}

	public function test_resolve_key_resolves_a_region_by_code(): void {
		$this->stub_settlement_suggest_transport(
			(string) json_encode(
				[
					[
						'region_code'  => 81,
						'region'       => 'Москва',
						'country_code' => 'RU',
					],
				]
			)
		);

		$provider = new \Woodev_Test_Cdek_Location_Provider();
		$record   = $provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':r81' );

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

	public function test_resolve_key_rejects_a_key_belonging_to_another_provider(): void {
		Functions\expect( 'wp_safe_remote_get' )->never();

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( \InvalidArgumentException::class );
		$provider->resolve_key( 'dadata:some-fias-id' );
	}

	public function test_resolve_key_throws_rather_than_returns_null_on_a_malformed_response(): void {
		$this->stub_settlement_suggest_transport( '{not-json' );

		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->expectException( Location_Provider_Exception::class );
		$provider->resolve_key( \Woodev_Test_Cdek_Location_Provider::PROVIDER_ID . ':44' );
	}
}
