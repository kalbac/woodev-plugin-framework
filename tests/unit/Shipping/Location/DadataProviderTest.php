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

	public function test_get_countries_defaults_to_ru_only(): void {
		$this->assertSame( [ 'RU' ], ( new Dadata_Provider() )->get_countries() );
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
		$this->assertSame( [ [ 'region_fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ] ], $body['locations'] );
	}

	public function test_suggest_with_no_parent_omits_locations_entirely(): void {
		$this->set_token( 'tok' );
		$this->stub_http_response( 200, (string) json_encode( self::region_level_suggestion_fixture() ) );

		( new Dadata_Provider() )->suggest( 'Моск', Location_Scope::for_country( 'RU', Location_Record::LEVEL_REGION ) );

		$body = $this->last_request_body();
		$this->assertArrayNotHasKey( 'locations', $body );
		$this->assertArrayNotHasKey( 'restrict_value', $body );
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
}
