<?php
/**
 * Unit tests for Dadata_Provider — the bundled server-side DaData location
 * provider (Task 7; spec D3, D4, D12, D15).
 *
 * Fixtures below are VERBATIM (field-for-field) example payloads taken from
 * DaData's own documentation (dadata.ru/api/suggest/address/, .../detect_address_by_ip/,
 * .../find-address/, .../clean/address/ — fetched 12.08.2026), NOT invented — see
 * `docs-internal/gotchas/an-invented-fixture-tests-your-assumptions-not-the-carrier.md`.
 * The one deliberate exception is `REGION_LEVEL_SUGGESTION_FIXTURE`, which is the
 * SAME verbatim `SUGGEST_ADDRESS_FIXTURE` payload with its city/street fields
 * nulled out — exactly what DaData itself returns when `from_bound`/`to_bound`
 * restrict a query to `region`/`area` (deeper fields come back null, not absent —
 * matching the null-field convention the real `IPLOCATE_FIXTURE` capture below
 * already shows for `area_fias_id` etc.), documented as a derived reduction of a
 * real capture rather than presented as its own independent one. No live DaData
 * capture was available (no credentials) — see `Dadata_Api_Client::clean_address()`'s
 * own docblock for where this matters most (the batch-vs-single clean response
 * shape).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Locality_Key;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-provider.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client
 */
final class DadataProviderTest extends TestCase {

	// -------------------------------------------------------------------------
	// Fixtures — VERBATIM DaData documentation examples (see file docblock).
	// -------------------------------------------------------------------------

	/**
	 * VERBATIM from dadata.ru/api/suggest/address/ (fetched 12.08.2026), the
	 * documented example response for `POST suggest/address`. Street-level
	 * (Moscow, ул Хабаровская) — note Moscow's `region` and `city` fields carry
	 * the SAME value/fias_id, a real DaData quirk (Moscow is both a federal
	 * subject and a city) worth preserving rather than "fixing".
	 *
	 * @return array<string, mixed>
	 */
	private static function suggest_address_fixture(): array {
		return [
			'suggestions' => [
				[
					'value'              => 'г Москва, ул Хабаровская',
					'unrestricted_value' => 'г Москва, ул Хабаровская',
					'data'               => [
						'postal_code'        => null,
						'country'            => 'Россия',
						'country_iso_code'   => 'RU',
						'federal_district'   => null,
						'region_fias_id'     => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'region_kladr_id'    => '7700000000000',
						'region_iso_code'    => 'RU-MOW',
						'region_with_type'   => 'г Москва',
						'region_type'        => 'г',
						'region_type_full'   => 'город',
						'region'             => 'Москва',
						'city_fias_id'       => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'city_kladr_id'      => '7700000000000',
						'city_with_type'     => 'г Москва',
						'city_type'          => 'г',
						'city_type_full'     => 'город',
						'city'               => 'Москва',
						'street_fias_id'     => '32fcb102-2a50-44c9-a00e-806420f448ea',
						'street_kladr_id'    => '77000000000713400',
						'street_with_type'   => 'ул Хабаровская',
						'street_type'        => 'ул',
						'street_type_full'   => 'улица',
						'street'             => 'Хабаровская',
						'fias_id'            => '32fcb102-2a50-44c9-a00e-806420f448ea',
						'fias_level'         => '7',
						'kladr_id'           => '77000000000713400',
						'geo_lat'            => '55.821168',
						'geo_lon'            => '37.82608',
						'qc_geo'             => '2',
					],
				],
			],
		];
	}

	/**
	 * The above, reduced to only its region-level fields with the deeper
	 * (city/street) fields nulled — see the file docblock for why this is
	 * documented as a derived reduction, not an independent capture.
	 *
	 * @return array<string, mixed>
	 */
	private static function region_level_suggestion_fixture(): array {
		return [
			'suggestions' => [
				[
					'value'              => 'г Москва',
					'unrestricted_value' => 'г Москва',
					'data'               => [
						'postal_code'      => null,
						'country'          => 'Россия',
						'country_iso_code' => 'RU',
						'region_fias_id'   => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'region_kladr_id'  => '7700000000000',
						'region_with_type' => 'г Москва',
						'region_type'      => 'г',
						'region'           => 'Москва',
						'city_fias_id'     => null,
						'city'             => null,
						'street_fias_id'   => null,
						'street'           => null,
						'fias_id'          => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'fias_level'       => '1',
					],
				],
			],
		];
	}

	/**
	 * A real settlement-level shape (Krasnodar), matching the confirmed field
	 * vocabulary — reused below both as a plain settlement suggestion AND, at
	 * `fias_level '65'`, as the planning-structure noise row.
	 *
	 * @param string $fias_level Value to place in `data.fias_level`.
	 *
	 * @return array<string, mixed>
	 */
	private static function settlement_suggestion( string $fias_level ): array {
		return [
			'value'              => 'г Краснодар',
			'unrestricted_value' => '350000, Краснодарский край, г Краснодар',
			'data'               => [
				'postal_code'      => '350000',
				'country'          => 'Россия',
				'country_iso_code' => 'RU',
				'federal_district' => 'Южный',
				'region_fias_id'   => 'd00e1013-16bd-4c09-b3d5-3cb09fc54bd8',
				'region_kladr_id'  => '2300000000000',
				'region_iso_code'  => 'RU-KDA',
				'region_with_type' => 'Краснодарский край',
				'region_type'      => 'край',
				'region'           => 'Краснодарский',
				'area_fias_id'     => null,
				'area'             => null,
				'city_fias_id'     => '7dfa745e-aa19-4688-b121-b655c11e482f',
				'city_kladr_id'    => '2300000100000',
				'city_with_type'   => 'г Краснодар',
				'city_type'        => 'г',
				'city'             => 'Краснодар',
				'fias_id'          => '7dfa745e-aa19-4688-b121-b655c11e482f',
				'fias_level'       => $fias_level,
			],
		];
	}

	/**
	 * A federal-city settlement row (fias_level '1', region_fias_id ===
	 * city_fias_id) — the shape `ru-settlement-moscow-duplicate.json` (a real
	 * committed live capture, see {@see self::load_dadata_fixture()}) actually
	 * returns for "г Москва". Built from the SAME field vocabulary as that
	 * fixture's first row (region/city both "Москва", same fias_id on both),
	 * kept as a synthetic single-row fixture here so the mutation tests below
	 * (a flipped `city_fias_id`, an injected `city_district`) can isolate ONE
	 * field at a time without hand-editing the real capture (forbidden — see
	 * the file docblock's fixture-provenance rule).
	 *
	 * @return array<string, mixed>
	 */
	private static function settlement_suggestion_at_level_1_federal_city(): array {
		return [
			'suggestions' => [
				[
					'value'              => 'г Москва',
					'unrestricted_value' => '101000, г Москва',
					'data'               => [
						'postal_code'    => '101000',
						'country'        => 'Россия',
						'country_iso_code' => 'RU',
						'region_fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'region_with_type' => 'г Москва',
						'region_type'    => 'г',
						'region'         => 'Москва',
						'city_fias_id'   => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'city_with_type' => 'г Москва',
						'city_type'      => 'г',
						'city'           => 'Москва',
						'city_district'  => null,
						'fias_id'        => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
						'fias_level'     => '1',
						'geo_lat'        => '55.75396',
						'geo_lon'        => '37.620393',
					],
				],
			],
		];
	}

	/**
	 * Loads a VERBATIM live DaData capture from `tests/_fixtures/dadata/` —
	 * these files carry their own `_capture` provenance block and must never be
	 * hand-edited (gotcha `an-invented-fixture-tests-your-assumptions-not-the-carrier`).
	 *
	 * @param string $filename Basename inside `tests/_fixtures/dadata/`.
	 *
	 * @return array{_capture: array<string, mixed>, response: array<string, mixed>}
	 */
	private static function load_dadata_fixture( string $filename ): array {
		$path = dirname( __DIR__, 4 ) . '/tests/_fixtures/dadata/' . $filename;

		return json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * VERBATIM from dadata.ru/api/detect_address_by_ip/ (fetched 12.08.2026,
	 * page fetch truncated after `city` — the docblock there explicitly notes
	 * further fields were not shown).
	 *
	 * @return array<string, mixed>
	 */
	private static function iplocate_fixture(): array {
		return [
			'location' => [
				'value'              => 'г Краснодар',
				'unrestricted_value' => '350000, Краснодарский край, г Краснодар',
				'data'               => [
					'postal_code'      => '350000',
					'country'          => 'Россия',
					'country_iso_code' => 'RU',
					'federal_district' => 'Южный',
					'region_fias_id'   => 'd00e1013-16bd-4c09-b3d5-3cb09fc54bd8',
					'region_kladr_id'  => '2300000000000',
					'region_iso_code'  => 'RU-KDA',
					'region_with_type' => 'Краснодарский край',
					'region_type'      => 'край',
					'region'           => 'Краснодарский',
					'area_fias_id'     => null,
					'area_kladr_id'    => null,
					'area_with_type'   => null,
					'area_type'        => null,
					'area_type_full'   => null,
					'area'             => null,
					'city_fias_id'     => '7dfa745e-aa19-4688-b121-b655c11e482f',
					'city_kladr_id'    => '2300000100000',
					'city_with_type'   => 'г Краснодар',
					'city_type'        => 'г',
					'city_type_full'   => 'город',
					'city'             => 'Краснодар',
					'fias_id'          => '7dfa745e-aa19-4688-b121-b655c11e482f',
				],
			],
		];
	}

	/**
	 * VERBATIM from dadata.ru/api/clean/address/ (fetched 12.08.2026) — the
	 * fields the doc fetch showed explicitly; `qc` set to `0` ("recognized
	 * confidently") since the doc's worked example result is unambiguous, but
	 * the exact `qc` value for THIS specific worked example was not shown by
	 * the fetch (documented here rather than asserted as doc-confirmed).
	 *
	 * @return array<string, mixed>
	 */
	private static function clean_result_fixture(): array {
		return [
			'source'      => 'мск сухонска 11/-89',
			'result'      => 'г Москва, ул Сухонская, д 11, кв 89',
			'postal_code' => '127642',
			'qc'          => 0,
			'region'      => 'Москва',
			'region_type' => 'г',
			'street'      => 'Сухонская',
			'street_type' => 'ул',
			'house'       => '11',
			'flat'        => '89',
		];
	}

	// -------------------------------------------------------------------------
	// setUp / helpers
	// -------------------------------------------------------------------------

	/** @var array<int, array{0: string, 1: array<mixed>}> */
	private array $do_action_calls = [];

	/** @var array{url: string, args: array<string, mixed>}|null */
	private ?array $last_request = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->do_action_calls = [];
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) {
				$this->do_action_calls[] = [ $tag, $args ];
			}
		);

		$this->last_request = null;
	}

	/**
	 * Stubs `wp_safe_remote_request` to return a single canned HTTP response
	 * and captures the outgoing url/args for assertions.
	 *
	 * @param int    $code Response code.
	 * @param string $body Raw response body.
	 *
	 * @return void
	 */
	private function stub_http_response( int $code, string $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 200 === $code ? 'OK' : 'Error' );

		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( $url, $args ) {
				$this->last_request = [
					'url'  => $url,
					'args' => $args,
				];

				return [];
			}
		);
	}

	/**
	 * Decodes {@see self::$last_request}'s JSON body for assertions.
	 *
	 * @return array<string, mixed>
	 */
	private function last_request_body(): array {
		$this->assertNotNull( $this->last_request, 'no HTTP request was captured' );

		return json_decode( (string) $this->last_request['args']['body'], true );
	}

	/**
	 * Whether `do_action( 'woodev_location_dadata_operation_failed', … )` fired
	 * for the given operation.
	 *
	 * @param string $operation Expected first extra argument.
	 *
	 * @return bool
	 */
	private function failure_was_logged( string $operation ): bool {
		foreach ( $this->do_action_calls as [ $tag, $args ] ) {
			if ( 'woodev_location_dadata_operation_failed' === $tag && ( $args[0] ?? null ) === $operation ) {
				return true;
			}
		}

		return false;
	}

	private function set_token( string $token, string $secret = '' ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $token, $secret ) {
				if ( 'woodev_location_token' === $name ) {
					return $token;
				}
				if ( 'woodev_location_clean_secret' === $name ) {
					return $secret;
				}

				return $default;
			}
		);
	}

	// -------------------------------------------------------------------------
	// Identity, countries, levels
	// -------------------------------------------------------------------------

	public function test_provider_id_matches_the_registry_default_provider_id(): void {
		$this->assertSame( Location_Provider_Registry::DEFAULT_PROVIDER_ID, ( new Dadata_Provider() )->get_id() );
	}

	/**
	 * Pins that this provider reads its credentials from the exact option
	 * names {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::SETTINGS_SERVICE_ID}
	 * ('location') implies — `woodev_location_token` /
	 * `woodev_location_clean_secret` — the same construction the registry
	 * itself uses for `active_provider`.
	 */
	public function test_option_names_match_the_registrys_settings_service_id(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'woodev_location_token' === $name ? 'tok' : $default;
			}
		);

		$this->assertTrue( ( new Dadata_Provider() )->is_configured() );
	}

	/**
	 * The nine served countries are the STORE OPERATOR'S market-scope decision
	 * (measured session s67/s68: ФИАС/ГАР for RU, OpenStreetMap for BY/KZ/UZ,
	 * GeoNames for AM/AZ/KG/TJ/TM), not a limit of the DaData API itself — see
	 * {@see Dadata_Provider::get_countries()}'s own docblock.
	 */
	public function test_get_countries_defaults_to_the_nine_served_countries(): void {
		$this->assertSame(
			[ 'RU', 'BY', 'KZ', 'UZ', 'AM', 'AZ', 'KG', 'TJ', 'TM' ],
			( new Dadata_Provider() )->get_countries()
		);
	}

	public function test_get_countries_is_widenable_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				if ( Dadata_Provider::FILTER_COUNTRIES === $tag ) {
					return [ 'RU', 'BY', 'KZ' ];
				}

				return $default;
			}
		);

		$this->assertSame( [ 'RU', 'BY', 'KZ' ], ( new Dadata_Provider() )->get_countries() );
	}

	public function test_dadata_serves_all_three_suggest_levels(): void {
		$this->assertSame( Location_Record::LEVELS, ( new Dadata_Provider() )->get_suggest_levels() );
	}

	// -------------------------------------------------------------------------
	// get_suggest_levels( $country ): per-country narrowing (measured tiers).
	// -------------------------------------------------------------------------

	/**
	 * ФИАС/ГАР (RU) and OpenStreetMap (BY, KZ, UZ) both resolve to house level —
	 * region/settlement/address all measured working (fixtures:
	 * ru-region-moscow, ru-settlement-moscow-duplicate, ru-address-tverskaya,
	 * by-settlement-minsk, by-address-nezavisimosti; KZ/UZ settlement measured
	 * directly, KZ/UZ address asserted by the design brief's own tier table —
	 * no committed fixture captures a KZ/UZ address-level response).
	 */
	public function test_get_suggest_levels_is_unnarrowed_for_ru_and_osm_tier_countries(): void {
		$provider = new Dadata_Provider();

		foreach ( [ 'RU', 'BY', 'KZ', 'UZ' ] as $country ) {
			$this->assertSame( Location_Record::LEVELS, $provider->get_suggest_levels( $country ), "country: $country" );
		}
	}

	/**
	 * GeoNames tier (AM, AZ, KG, TJ, TM) resolves to city only — "address"
	 * (street/house bound) is measured EMPTY (fixture: am-address-empty-tier2.json;
	 * the same street/house-bound query returning zero rows for AZ/KG/TJ/TM is
	 * the design brief's own stated measurement, not independently re-derived
	 * here per fixture per country).
	 */
	public function test_get_suggest_levels_excludes_address_for_geonames_tier_countries(): void {
		$provider = new Dadata_Provider();

		foreach ( [ 'AM', 'AZ', 'KG', 'TJ', 'TM' ] as $country ) {
			$this->assertSame(
				[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
				$provider->get_suggest_levels( $country ),
				"country: $country"
			);
		}
	}

	public function test_get_suggest_levels_narrowing_is_case_insensitive_and_trims_whitespace(): void {
		$provider = new Dadata_Provider();

		$this->assertSame(
			[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
			$provider->get_suggest_levels( ' am ' )
		);
	}

	/**
	 * The no-country call (used by every pre-existing country-blind call site,
	 * e.g. Location_Service::get_supported_countries()) must remain the
	 * unnarrowed union across every level this provider can EVER answer for ANY
	 * country — narrowing is an ADDITIVE, opt-in refinement, not a replacement
	 * of the existing contract.
	 */
	public function test_get_suggest_levels_without_a_country_stays_unnarrowed(): void {
		$this->assertSame( Location_Record::LEVELS, ( new Dadata_Provider() )->get_suggest_levels() );
	}

	/**
	 * A country outside the nine served ones narrows to nothing — DaData's own
	 * `get_countries()` gate is the caller's job (Location_Service), but the
	 * levels answer itself must not pretend to serve a country it does not
	 * cover at all.
	 */
	public function test_get_suggest_levels_is_empty_for_an_uncovered_country(): void {
		$this->assertSame( [], ( new Dadata_Provider() )->get_suggest_levels( 'US' ) );
	}

	// -------------------------------------------------------------------------
	// is_configured() / capabilities
	// -------------------------------------------------------------------------

	public function test_is_not_configured_without_a_token(): void {
		$this->set_token( '' );
		$this->assertFalse( ( new Dadata_Provider() )->is_configured() );
	}

	public function test_is_configured_with_a_token(): void {
		$this->set_token( 'tok' );
		$this->assertTrue( ( new Dadata_Provider() )->is_configured() );
	}

	public function test_capabilities_do_not_include_normalize_without_a_secret(): void {
		$this->set_token( 'tok', '' );
		$this->assertNotContains( 'normalize', ( new Dadata_Provider() )->get_capabilities() );
	}

	public function test_capabilities_include_normalize_with_a_secret_configured(): void {
		$this->set_token( 'tok', 'sec' );
		$this->assertContains( 'normalize', ( new Dadata_Provider() )->get_capabilities() );
	}

	public function test_capabilities_never_include_list(): void {
		$this->set_token( 'tok', 'sec' );
		$this->assertNotContains( 'list', ( new Dadata_Provider() )->get_capabilities() );
	}

	public function test_capabilities_include_locate_regardless_of_secret(): void {
		$this->set_token( 'tok', '' );
		$this->assertContains( 'locate', ( new Dadata_Provider() )->get_capabilities() );
	}

	// -------------------------------------------------------------------------
	// suggest()
	// -------------------------------------------------------------------------

	public function test_suggest_returns_empty_for_a_blank_query_without_any_http_call(): void {
		$this->set_token( 'tok' );
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertSame( [], ( new Dadata_Provider() )->suggest( '  ', Location_Scope::for_country( 'RU', 'region' ) ) );
	}

	public function test_suggest_returns_empty_when_unconfigured_without_any_http_call(): void {
		$this->set_token( '' );
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertSame( [], ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', 'region' ) ) );
	}

	public function test_suggest_at_region_level_maps_a_record(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::region_level_suggestion_fixture() ) );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$this->assertCount( 1, $records );
		$record = $records[0];
		$this->assertSame( 'dadata:0c5b2444-70a0-4932-980c-b4dc0d3f02b5', $record->key() );
		$this->assertSame( 'dadata', $record->provider_id() );
		$this->assertSame( Location_Record::LEVEL_REGION, $record->level() );
		$this->assertSame( 'RU', $record->country() );
		$this->assertSame( [ 'name' => 'Москва', 'type' => 'г' ], $record->region() );
		$this->assertSame( 'г Москва', $record->label() );
	}

	public function test_suggest_at_region_level_sends_region_area_bounds(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::region_level_suggestion_fixture() ) );

		( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$body = $this->last_request_body();
		$this->assertSame( [ 'value' => 'region' ], $body['from_bound'] );
		$this->assertSame( [ 'value' => 'area' ], $body['to_bound'] );
	}

	public function test_suggest_at_address_level_sends_street_house_bounds(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::suggest_address_fixture() ) );

		( new Dadata_Provider() )->suggest( 'Хабаровская', Location_Scope::for_country( 'RU', Location_Record::LEVEL_ADDRESS ) );

		$body = $this->last_request_body();
		$this->assertSame( [ 'value' => 'street' ], $body['from_bound'] );
		$this->assertSame( [ 'value' => 'house' ], $body['to_bound'] );
	}

	public function test_suggest_at_settlement_level_sends_city_settlement_bounds(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => [ self::settlement_suggestion( '4' ) ] ] ) );

		( new Dadata_Provider() )->suggest( 'Красн', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$body = $this->last_request_body();
		$this->assertSame( [ 'value' => 'city' ], $body['from_bound'] );
		$this->assertSame( [ 'value' => 'settlement' ], $body['to_bound'] );
	}

	public function test_suggest_at_settlement_level_drops_planning_structure_noise_rows(): void {
		$this->set_token( 'tok' );
		$noise  = self::settlement_suggestion( '65' );
		$real   = self::settlement_suggestion( '4' );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => [ $noise, $real ] ] ) );

		$records = ( new Dadata_Provider() )->suggest( 'Красн', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 1, $records, 'the fias_level=65 row must be filtered out' );
		$this->assertSame( 'dadata:7dfa745e-aa19-4688-b121-b655c11e482f', $records[0]->key() );
	}

	// -------------------------------------------------------------------------
	// Settlement-level granularity rule (measured defect: ru-settlement-moscow-
	// duplicate.json — г Москва and г Москва, р-н Москворечье-Сабурово both come
	// back at fias_level=1 with the SAME city_fias_id, so a city_fias_id-keyed
	// dedup would collapse them into one locality carrying whichever record
	// happened to win, silently dropping the other's quality (the first has a
	// postcode/coordinates, the second — a finer city-district row — has
	// neither). Fixed by GRANULARITY (reject the finer row), not deduplication.
	// -------------------------------------------------------------------------

	public function test_suggest_settlement_level_federal_city_row_is_kept_via_region_equals_city_fias_id(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::settlement_suggestion_at_level_1_federal_city() ) );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 1, $records, 'a federal-city row (region_fias_id === city_fias_id) at fias_level=1 must survive' );
		$this->assertSame( 'г Москва', $records[0]->label() );
	}

	public function test_suggest_settlement_level_non_federal_city_fias_level_1_row_is_rejected(): void {
		$this->set_token( 'tok' );
		$row                        = self::settlement_suggestion_at_level_1_federal_city();
		$row['suggestions'][0]['data']['city_fias_id'] = 'a-different-fias-id-than-region';
		$this->stub_http_response( 200, (string) json_encode( $row ) );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 0, $records, 'fias_level=1 with region_fias_id !== city_fias_id is finer than settlement (e.g. a district) and must be rejected' );
	}

	public function test_suggest_settlement_level_rejects_a_row_carrying_a_non_empty_city_district(): void {
		$this->set_token( 'tok' );
		$row = self::settlement_suggestion_at_level_1_federal_city();
		$row['suggestions'][0]['data']['city_district'] = 'Москворечье-Сабурово';
		$this->stub_http_response( 200, (string) json_encode( $row ) );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 0, $records, 'a city_district is finer than a settlement, even on an otherwise-federal-city row' );
	}

	/**
	 * Country-agnostic rule (applies everywhere, including where fias_level is
	 * '-1'): a settlement-level row is usable only when city/settlement is
	 * filled AND street/house are NOT — a row carrying street/house data at a
	 * settlement-bound query is finer than what was asked for.
	 */
	public function test_suggest_settlement_level_rejects_a_row_carrying_street_data_even_at_fias_level_minus_1(): void {
		$this->set_token( 'tok' );
		$row                                     = self::settlement_suggestion_at_level_1_federal_city();
		$row['suggestions'][0]['data']['fias_level'] = '-1';
		$row['suggestions'][0]['data']['street']      = 'Тверская';

		$this->stub_http_response( 200, (string) json_encode( $row ) );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 0, $records );
	}

	/**
	 * Pins the measured defect end-to-end against the VERBATIM live capture:
	 * `ru-settlement-moscow-duplicate.json` (`tests/_fixtures/dadata/`) holds 10
	 * raw suggestion rows for query "Москв" at the settlement bound; exactly one
	 * (the "р-н Москворечье-Сабурово" city-district row) must be rejected, the
	 * federal city "г Москва" must survive, and no two surviving records may
	 * share a locality key.
	 */
	public function test_suggest_settlement_level_moscow_duplicate_fixture_yields_no_shared_keys_and_keeps_the_federal_city(): void {
		$this->set_token( 'tok' );
		$fixture = self::load_dadata_fixture( 'ru-settlement-moscow-duplicate.json' );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => $fixture['response']['suggestions'] ] ) );

		$records = ( new Dadata_Provider() )->suggest( 'Москв', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertCount( 9, $records, '10 raw rows minus the 1 city-district row' );

		$keys = array_map( static fn( Location_Record $record ) => $record->key(), $records );
		$this->assertSame( $keys, array_unique( $keys ), 'no two surviving settlement suggestions may share a locality key' );

		$labels = array_map( static fn( Location_Record $record ) => $record->label(), $records );
		$this->assertContains( 'г Москва', $labels, 'the federal city must not be thrown out with the duplicate' );
		$this->assertNotContains( 'г Москва, р-н Москворечье-Сабурово', $labels, 'the finer city-district row must be rejected' );
	}

	public function test_suggest_settlement_record_carries_postcode_lat_lon_and_full_raw(): void {
		$this->set_token( 'tok' );
		$fixture = [ 'suggestions' => [ self::settlement_suggestion( '4' ) ] ];
		$this->stub_http_response( 200, (string) json_encode( $fixture ) );

		$records = ( new Dadata_Provider() )->suggest( 'Красн', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$record = $records[0];
		$this->assertSame( '350000', $record->postcode() );
		$this->assertSame( [ 'name' => 'Краснодар', 'type' => 'г' ], $record->settlement() );

		// raw() must carry the FULL DaData data object, untouched (spec D12).
		$this->assertSame( $fixture['suggestions'][0]['data'], $record->raw() );
	}

	public function test_suggest_settlement_record_also_carries_its_parent_region_component(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => [ self::settlement_suggestion( '4' ) ] ] ) );

		$records = ( new Dadata_Provider() )->suggest( 'Красн', Location_Scope::for_country( 'RU', Location_Record::LEVEL_SETTLEMENT ) );

		$this->assertSame( [ 'name' => 'Краснодарский', 'type' => 'край' ], $records[0]->region() );
	}

	public function test_a_suggestion_missing_a_fias_id_derives_the_key_deterministically(): void {
		$this->set_token( 'tok' );
		$no_fias = self::region_level_suggestion_fixture();
		unset( $no_fias['suggestions'][0]['data']['fias_id'] );

		$this->stub_http_response( 200, (string) json_encode( $no_fias ) );
		$records_a = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$this->stub_http_response( 200, (string) json_encode( $no_fias ) );
		$records_b = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$this->assertCount( 1, $records_a );
		$this->assertStringStartsWith( 'dadata:', $records_a[0]->key() );
		$this->assertSame( $records_a[0]->key(), $records_b[0]->key(), 'derivation must be deterministic across calls' );

		// Independently confirm it matches the shared Locality_Key helper directly.
		$expected = Locality_Key::derive( 'dadata', $no_fias['suggestions'][0]['data'] );
		$this->assertSame( $expected, $records_a[0]->key() );
	}

	public function test_suggest_scoped_by_a_native_dadata_region_parent_sends_locations_and_restrict_value(): void {
		$this->set_token( 'tok' );

		$region_fixture = self::region_level_suggestion_fixture();
		$this->stub_http_response( 200, (string) json_encode( $region_fixture ) );
		$region_record = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) )[0];

		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => [ self::settlement_suggestion( '4' ) ] ] ) );
		( new Dadata_Provider() )->suggest( 'Красн', Location_Scope::within( $region_record, Location_Record::LEVEL_SETTLEMENT ) );

		$body = $this->last_request_body();
		$this->assertTrue( $body['restrict_value'] );
		// The COUNTRY rides along with the parent id. Measured 13.08.2026: outside Russia the
		// "fias" ids are OpenStreetMap-derived, and DaData's `locations` filter returns ZERO
		// suggestions for one unless it is told which country's registry to read it in — see
		// `build_locations_constraint()`'s own table. Harmless for RU, load-bearing elsewhere.
		$this->assertSame(
			[
				[
					'country_iso_code' => 'RU',
					'region_fias_id'   => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
				],
			],
			$body['locations']
		);
	}

	/**
	 * P2 review finding: a country-wide suggest (no parent — the normal path
	 * for the first field the customer touches) must still be scoped by the
	 * scope's own country, otherwise `/location/suggest?country=XX` is not
	 * actually restricted to `XX` at DaData. Matches the reference client's
	 * own usage (`plugins-reference/woocommerce-edostavka/assets/js/frontend/fields-autocomplete.js`):
	 * the region field sends `locations: { country_iso_code: countryISOCode }`
	 * with NO `restrict_value` — that flag is reserved for an actual parent
	 * locality constraint (region/city), which a bare country floor is not.
	 */
	public function test_suggest_with_no_parent_scopes_locations_by_country_without_restrict_value(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::region_level_suggestion_fixture() ) );

		( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$body = $this->last_request_body();
		$this->assertSame( [ [ 'country_iso_code' => 'RU' ] ], $body['locations'] );
		$this->assertArrayNotHasKey( 'restrict_value', $body, 'a bare country floor must not strip the returned label' );
	}

	public function test_suggest_with_no_parent_scopes_by_the_scopes_own_country_not_a_hardcoded_one(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::region_level_suggestion_fixture() ) );

		( new Dadata_Provider() )->suggest( 'Мин', Location_Scope::for_country( 'BY', Location_Record::LEVEL_REGION ) );

		$body = $this->last_request_body();
		$this->assertSame( [ [ 'country_iso_code' => 'BY' ] ], $body['locations'] );
	}

	public function test_suggest_http_failure_degrades_to_empty_and_is_logged(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 500, '' );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$this->assertSame( [], $records );
		$this->assertTrue( $this->failure_was_logged( 'suggest' ) );
	}

	public function test_suggest_401_response_degrades_to_empty_and_is_logged(): void {
		$this->set_token( 'bad-token' );
		$this->stub_http_response( 401, '' );

		$records = ( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$this->assertSame( [], $records );
		$this->assertTrue( $this->failure_was_logged( 'suggest' ) );
	}

	// -------------------------------------------------------------------------
	// Key derivation across tiers — every fixture's key must come from the
	// provider's own `fias_id` (a real GUID for RU/ФИАС, a bare number for
	// GeoNames, `relation:`/`way:`/`node:` for OpenStreetMap), never fall
	// through to Locality_Key::derive()'s hash. Pinned against the VERBATIM
	// committed live captures, not invented payloads.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> filename => [ country, level, expected native id ]
	 */
	public function dadata_fixture_key_provider(): array {
		return [
			'ru-region-moscow'              => [ 'RU', Location_Record::LEVEL_REGION, '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ],
			'ru-address-tverskaya'          => [ 'RU', Location_Record::LEVEL_ADDRESS, '0ecde158-a58f-43af-9707-aa6dd3484b56' ],
			'by-settlement-minsk'           => [ 'BY', Location_Record::LEVEL_SETTLEMENT, 'relation:59195' ],
			'by-address-nezavisimosti'      => [ 'BY', Location_Record::LEVEL_ADDRESS, 'way:1247091839' ],
			'kz-settlement-almaty'          => [ 'KZ', Location_Record::LEVEL_SETTLEMENT, 'relation:2465058' ],
			'uz-settlement-samarkand'       => [ 'UZ', Location_Record::LEVEL_SETTLEMENT, 'relation:15589846' ],
			'am-settlement-gyumri'          => [ 'AM', Location_Record::LEVEL_SETTLEMENT, '616635' ],
			'az-settlement-ganja'           => [ 'AZ', Location_Record::LEVEL_SETTLEMENT, '586523' ],
			'kg-settlement-osh'             => [ 'KG', Location_Record::LEVEL_SETTLEMENT, '1527534' ],
			'tj-settlement-khujand'         => [ 'TJ', Location_Record::LEVEL_SETTLEMENT, '1514879' ],
			'tm-settlement-mary'            => [ 'TM', Location_Record::LEVEL_SETTLEMENT, '1218667' ],
		];
	}

	/**
	 * @dataProvider dadata_fixture_key_provider
	 */
	public function test_the_first_suggestion_of_each_tier_fixture_keys_by_its_real_fias_id( string $country, string $level, string $expected_native_id ): void {
		$this->set_token( 'tok' );

		// (country, level) unambiguously identifies one fixture file among the
		// 13 committed captures.
		$filenames = [
			'RU:region'     => 'ru-region-moscow.json',
			'RU:address'    => 'ru-address-tverskaya.json',
			'BY:settlement' => 'by-settlement-minsk.json',
			'BY:address'    => 'by-address-nezavisimosti.json',
			'KZ:settlement' => 'kz-settlement-almaty.json',
			'UZ:settlement' => 'uz-settlement-samarkand.json',
			'AM:settlement' => 'am-settlement-gyumri.json',
			'AZ:settlement' => 'az-settlement-ganja.json',
			'KG:settlement' => 'kg-settlement-osh.json',
			'TJ:settlement' => 'tj-settlement-khujand.json',
			'TM:settlement' => 'tm-settlement-mary.json',
		];

		$fixture = self::load_dadata_fixture( $filenames[ $country . ':' . $level ] );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => $fixture['response']['suggestions'] ] ) );

		$records = ( new Dadata_Provider() )->suggest( 'q', Location_Scope::for_country( $country, $level ) );

		$this->assertNotEmpty( $records );
		$this->assertSame(
			'dadata:' . $expected_native_id,
			$records[0]->key(),
			'the key must be composed from the real provider fias_id, never Locality_Key::derive()\'s hash'
		);
	}

	/**
	 * am-address-empty-tier2.json pins the OTHER half of the D15 measurement:
	 * a street-bounded (address-level) query for a GeoNames-tier country
	 * measured genuinely EMPTY — not a provider bug, DaData itself returns zero
	 * rows. The provider must pass this through as an empty result, not an
	 * error.
	 */
	public function test_am_address_level_fixture_is_measured_empty_not_a_provider_bug(): void {
		$this->set_token( 'tok' );
		$fixture = self::load_dadata_fixture( 'am-address-empty-tier2.json' );
		$this->stub_http_response( 200, (string) json_encode( [ 'suggestions' => $fixture['response']['suggestions'] ] ) );

		$records = ( new Dadata_Provider() )->suggest( 'Ереван Абовяна', Location_Scope::for_country( 'AM', Location_Record::LEVEL_ADDRESS ) );

		$this->assertSame( [], $records );
	}

	/**
	 * `Locality_Key::parse()` splits on the FIRST colon only (spec §3) — a
	 * native id that itself contains a colon (every OSM-tier id: `relation:X`,
	 * `way:X`, `node:X`) must still parse back to exactly two parts. Verified
	 * directly against the shared helper, not assumed.
	 */
	public function test_an_osm_tier_key_parses_correctly_on_the_first_colon_only(): void {
		$this->assertSame( [ 'dadata', 'relation:59195' ], Locality_Key::parse( 'dadata:relation:59195' ) );
		$this->assertSame( [ 'dadata', 'way:1247091839' ], Locality_Key::parse( 'dadata:way:1247091839' ) );
	}

	// -------------------------------------------------------------------------
	// locate()
	// -------------------------------------------------------------------------

	public function test_locate_maps_a_settlement_record(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::iplocate_fixture() ) );

		$record = ( new Dadata_Provider() )->locate( '1.2.3.4' );

		$this->assertNotNull( $record );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $record->level() );
		$this->assertSame( 'dadata:7dfa745e-aa19-4688-b121-b655c11e482f', $record->key() );
		$this->assertSame( [ 'name' => 'Краснодар', 'type' => 'г' ], $record->settlement() );
	}

	public function test_locate_returns_null_when_the_response_has_no_location(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( [] ) );

		$this->assertNull( ( new Dadata_Provider() )->locate( '1.2.3.4' ) );
	}

	public function test_locate_returns_null_when_unconfigured(): void {
		$this->set_token( '' );
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertNull( ( new Dadata_Provider() )->locate( '1.2.3.4' ) );
	}

	public function test_locate_http_failure_degrades_to_null_and_is_logged(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 500, '' );

		$this->assertNull( ( new Dadata_Provider() )->locate( '1.2.3.4' ) );
		$this->assertTrue( $this->failure_was_logged( 'locate' ) );
	}

	// -------------------------------------------------------------------------
	// normalize()
	// -------------------------------------------------------------------------

	public function test_normalize_maps_a_record_when_a_secret_is_configured(): void {
		$this->set_token( 'tok', 'sec' );
		$this->stub_http_response( 200, (string) json_encode( [ self::clean_result_fixture() ] ) );

		$record = ( new Dadata_Provider() )->normalize( 'мск сухонска 11/-89', Location_Scope::for_country( 'RU', Location_Record::LEVEL_ADDRESS ) );

		$this->assertNotNull( $record );
		$this->assertSame( 'г Москва, ул Сухонская, д 11, кв 89', $record->label() );
		$this->assertSame( '127642', $record->postcode() );
		$this->assertSame( '11', $record->house() );
		$this->assertSame( '89', $record->flat() );
	}

	public function test_normalize_sends_the_free_form_query_as_a_json_array_body(): void {
		$this->set_token( 'tok', 'sec' );
		$this->stub_http_response( 200, (string) json_encode( [ self::clean_result_fixture() ] ) );

		( new Dadata_Provider() )->normalize( 'мск сухонска 11/-89', Location_Scope::for_country( 'RU', Location_Record::LEVEL_ADDRESS ) );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( [ 'мск сухонска 11/-89' ], $body );
	}

	public function test_normalize_without_a_secret_degrades_to_null_and_is_logged(): void {
		$this->set_token( 'tok', '' );
		// No X-Secret header configured -> DaData rejects the Clean API call.
		$this->stub_http_response( 401, '' );

		$this->assertNull( ( new Dadata_Provider() )->normalize( 'мск сухонска 11/-89', Location_Scope::for_country( 'RU', Location_Record::LEVEL_ADDRESS ) ) );
		$this->assertTrue( $this->failure_was_logged( 'normalize' ) );
	}

	public function test_normalize_returns_null_for_an_empty_result(): void {
		$this->set_token( 'tok', 'sec' );
		$fixture           = self::clean_result_fixture();
		$fixture['result'] = '';
		$this->stub_http_response( 200, (string) json_encode( [ $fixture ] ) );

		$this->assertNull( ( new Dadata_Provider() )->normalize( 'garbage', Location_Scope::for_country( 'RU', Location_Record::LEVEL_ADDRESS ) ) );
	}

	// -------------------------------------------------------------------------
	// Registry integration — an OBSERVABLE end-to-end assertion, not reflection.
	// -------------------------------------------------------------------------

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	public function test_the_provider_registers_through_the_real_registry_once_the_class_exists(): void {
		$this->set_token( 'tok' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertTrue( $registry->has_provider( 'dadata' ) );
		$this->assertInstanceOf( Dadata_Provider::class, $registry->get_providers()['dadata'] );
		// No store setting saved yet -> falls back to the DEFAULT_PROVIDER_ID, dadata.
		$this->assertInstanceOf( Dadata_Provider::class, $registry->get_active_provider() );
	}

	public function test_a_foreign_parent_id_is_sent_WITH_its_country_or_dadata_matches_nothing(): void {
		$this->set_token( 'tok' );

		// A real Tashkent record as DaData itself returns it: the "fias" ids are
		// OpenStreetMap-derived, not FIAS (measured 13.08.2026).
		$tashkent = Location_Record::from_array(
			[
				'key'         => 'dadata:relation:2216724',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'UZ',
				'settlement'  => [ 'name' => 'Ташкент', 'type' => 'г' ],
				'label'       => 'Узбекистан, г Ташкент',
				'raw'         => [
					'country_iso_code'   => 'UZ',
					'fias_id'            => 'relation:2216724',
					'city_fias_id'       => 'relation:2216724',
					'region_fias_id'     => 'relation:2216724',
					'city'               => 'Ташкент',
				],
			]
		);

		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Provider() )->suggest( 'Юнус', Location_Scope::within( $tashkent, Location_Record::LEVEL_ADDRESS ) );

		$locations = $this->last_request_body()['locations'];

		// Without the country this exact constraint returns ZERO suggestions from the live
		// API while the same query unscoped returns street after street — the defect the
		// operator hit on the rig (s70): pick Tashkent, and the address field goes dead.
		$this->assertSame( 'UZ', $locations[0]['country_iso_code'] );
		$this->assertSame( 'relation:2216724', $locations[0]['city_fias_id'] );
	}

	public function test_a_components_only_parent_also_carries_its_country(): void {
		$this->set_token( 'tok' );

		// The D15 fallback path: a parent record from ANOTHER provider, so there is no
		// DaData id to read and only names remain. It needs the country for the same reason.
		$foreign = Location_Record::from_array(
			[
				'key'         => 'cdek:44',
				'provider_id' => 'cdek',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'KZ',
				'region'      => [ 'name' => 'Алматинская', 'type' => 'обл' ],
				'settlement'  => [ 'name' => 'Алматы', 'type' => 'г' ],
				'label'       => 'Алматы',
			]
		);

		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( new Dadata_Provider() )->suggest( 'Абая', Location_Scope::within( $foreign, Location_Record::LEVEL_ADDRESS ) );

		$locations = $this->last_request_body()['locations'];

		$this->assertSame( 'KZ', $locations[0]['country_iso_code'] );
		$this->assertSame( 'Алматы', $locations[0]['city'] );
	}
}
