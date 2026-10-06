<?php
/**
 * Unit tests for DaData `findById/delivery` (#1136): the client method, the
 * token-free `Dadata_Provider::delivery_ids()` and the `Location_Service`
 * façade a carrier adapter calls.
 *
 * Fixtures under `tests/_fixtures/dadata/findById-delivery-*.json` and
 * `suggest-address-*.json` are LIVE captures (06.10.2026, token redacted) made by
 * the CDEK plugin's measurement of the service — see their `note` fields.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Location\Customer_Location_Store;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Exception;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Resolution_Cache;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-provider.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client::find_by_id_delivery
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Request::find_by_id_delivery
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::delivery_ids
 * @covers \Woodev\Framework\Shipping\Location\Location_Service::get_delivery_ids
 */
final class DadataDeliveryIdsTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null */
	private ?array $last_request = null;

	/** @var int How many HTTP requests were made. */
	private int $http_calls = 0;

	/** @var array<string, mixed> In-memory transients. */
	private array $transients = [];

	/** @var array<string, int> Transient TTLs as written. */
	private array $transient_ttls = [];

	/** @var array<int, array{0: string, 1: array<int, mixed>}> */
	private array $do_action_calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->last_request   = null;
		$this->http_calls     = 0;
		$this->transients     = [];
		$this->transient_ttls = [];
		$this->do_action_calls = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( string $tag, ...$args ) {
				$this->do_action_calls[] = [ $tag, $args ];
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ]     = $value;
				$this->transient_ttls[ $key ] = (int) $ttl;

				return true;
			}
		);

		$this->set_token( 'tok' );
	}

	private function set_token( string $token ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $token ) {
				return 'woodev_location_token' === $name ? $token : $default;
			}
		);
	}

	private function stub_http_response( int $code, string $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 200 === $code ? 'OK' : 'Error' );

		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( $url, $args ) {
				++$this->http_calls;
				$this->last_request = [
					'url'  => $url,
					'args' => $args,
				];

				return [];
			}
		);
	}

	/**
	 * The recorded exchange's response body, re-encoded as the wire JSON.
	 */
	private function stub_fixture_response( string $fixture ): void {
		$this->stub_http_response( 200, (string) json_encode( self::fixture( $fixture )['response']['body'] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function fixture( string $name ): array {
		$path = dirname( __DIR__, 3 ) . '/_fixtures/dadata/' . $name . '.json';

		return json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * A DaData-produced record whose `raw()` is the recorded `suggest/address` row's `data`.
	 */
	private static function record_from_suggest_fixture( string $fixture ): Location_Record {
		$row = self::fixture( $fixture )['response']['body']['suggestions'][0];

		return self::dadata_record( $row['data'] );
	}

	/**
	 * @param mixed $raw The record's raw payload.
	 */
	private static function dadata_record( $raw, string $provider = 'dadata', string $country = 'RU' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $provider . ':2e30ca06-a155-495e-966d-d2f47764c452',
				'provider_id' => $provider,
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => $country,
				'label'       => 'Тест',
				'raw'         => $raw,
			]
		);
	}

	private function failure_was_logged( string $operation ): bool {
		foreach ( $this->do_action_calls as [ $tag, $args ] ) {
			if ( 'woodev_location_dadata_operation_failed' === $tag && ( $args[0] ?? null ) === $operation ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A client / provider with the locale seam pinned, so no `language` key is sent
	 * and the suite does not depend on which WP functions earlier tests defined.
	 */
	private static function client( string $token = 'tok' ): Dadata_Api_Client {
		return new class( $token ) extends Dadata_Api_Client {

			protected function current_locale(): string {
				return '';
			}
		};
	}

	private static function provider(): Dadata_Provider {
		return new class extends Dadata_Provider {

			protected function make_client( string $token, string $secret ): Dadata_Api_Client {
				return new class( $token, $secret ) extends Dadata_Api_Client {

					protected function current_locale(): string {
						return '';
					}
				};
			}
		};
	}

	// -------------------------------------------------------------------------
	// Dadata_Api_Client::find_by_id_delivery()
	// -------------------------------------------------------------------------

	public function test_client_posts_the_id_to_find_by_id_delivery_and_returns_the_data_object(): void {
		$this->stub_fixture_response( 'findById-delivery-novosibirsk-by-fias' );

		$data = self::client( 'my-token' )->find_by_id_delivery( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/delivery', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );
		$this->assertSame( 'Token my-token', $this->last_request['args']['headers']['Authorization'] );
		$this->assertSame( [ 'query' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ], json_decode( (string) $this->last_request['args']['body'], true ) );
		$this->assertSame( '270', $data['cdek_id'], 'cdek_id is a STRING on the wire' );
		$this->assertSame( '7765', $data['boxberry_id'] );
	}

	public function test_client_returns_null_for_an_empty_suggestion_set(): void {
		$this->stub_fixture_response( 'findById-delivery-street-level-id-gives-nothing' );

		$this->assertNull( self::client()->find_by_id_delivery( '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ) );
	}

	public function test_client_throws_for_a_non_empty_set_without_a_readable_data_object(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x"}]}' );

		$this->expectException( \Woodev_API_Exception::class );
		self::client()->find_by_id_delivery( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' );
	}

	public function test_client_throws_on_an_http_error(): void {
		$this->stub_http_response( 500, '' );

		$this->expectException( \Woodev_API_Exception::class );
		self::client()->find_by_id_delivery( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' );
	}

	// -------------------------------------------------------------------------
	// Dadata_Provider::delivery_ids()
	// -------------------------------------------------------------------------

	public function test_delivery_ids_returns_the_carrier_ids_and_drops_the_echoed_identity_fields(): void {
		$this->stub_fixture_response( 'findById-delivery-novosibirsk-by-fias' );
		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456', 'fias_id' => '0aa1c1c2-0000-4000-8000-000000000001', 'city_fias_id' => '5400000100000' ] );

		$ids = self::provider()->delivery_ids( $record );

		$this->assertSame(
			[
				'boxberry_id' => '7765',
				'cdek_id'     => '270',
				'dpd_id'      => '49455627',
			],
			$ids
		);
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_delivery_ids_omits_carriers_dadata_sent_as_null(): void {
		$this->stub_fixture_response( 'findById-delivery-peno-by-fias' );

		$ids = self::provider()->delivery_ids( self::record_from_suggest_fixture( 'suggest-address-peno' ) );

		$this->assertSame( [ 'cdek_id' => '24143' ], $ids );
	}

	public function test_delivery_ids_queries_the_settlement_fias_id_before_the_city_fias_id(): void {
		$this->stub_fixture_response( 'findById-delivery-peno-by-fias' );
		$record = self::dadata_record(
			[
				'fias_id'            => '11111111-1111-4111-8111-111111111111',
				'settlement_fias_id' => '22222222-2222-4222-8222-222222222222',
				'city_fias_id'       => '33333333-3333-4333-8333-333333333333',
			]
		);

		self::provider()->delivery_ids( $record );

		$this->assertSame( [ 'query' => '22222222-2222-4222-8222-222222222222' ], json_decode( (string) $this->last_request['args']['body'], true ) );
	}

	public function test_delivery_ids_uses_the_city_fias_id_of_an_address_level_record_not_its_street_id(): void {
		$this->stub_fixture_response( 'findById-delivery-moscow-by-fias' );
		$record = self::record_from_suggest_fixture( 'suggest-address-street-level-row' );
		$raw    = $record->raw();

		$this->assertNotSame( $raw['city_fias_id'], $raw['fias_id'], 'sanity: the fixture row is a street-level one' );

		self::provider()->delivery_ids( $record );

		$this->assertSame( [ 'query' => $raw['city_fias_id'] ], json_decode( (string) $this->last_request['args']['body'], true ) );
	}

	public function test_delivery_ids_never_asks_by_kladr_even_when_the_record_carries_only_kladr_ids(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$record = self::dadata_record(
			[
				'kladr_id'            => '6900001500000',
				'city_kladr_id'       => '6900001500000',
				'settlement_kladr_id' => '6900001501600',
			]
		);

		$this->assertSame( [], self::provider()->delivery_ids( $record ) );
	}

	public function test_delivery_ids_is_empty_without_a_request_when_the_record_carries_no_city_or_settlement_fias_id(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$region_only = self::dadata_record( [ 'region_fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5', 'city_fias_id' => null, 'settlement_fias_id' => '' ] );

		$this->assertSame( [], self::provider()->delivery_ids( $region_only ) );
	}

	public function test_delivery_ids_is_empty_without_a_request_for_a_non_russian_record(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$record = self::dadata_record( [ 'city_fias_id' => 'relation:1746396' ], 'dadata', 'AM' );

		$this->assertSame( [], self::provider()->delivery_ids( $record ) );
	}

	public function test_delivery_ids_is_empty_for_a_record_without_an_array_payload(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertSame( [], self::provider()->delivery_ids( self::dadata_record( null ) ) );
	}

	public function test_delivery_ids_is_empty_for_a_record_another_provider_produced(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ], 'cdek' );

		$this->assertSame( [], self::provider()->delivery_ids( $record ) );
	}

	public function test_delivery_ids_is_empty_and_makes_no_request_when_dadata_is_not_configured(): void {
		$this->set_token( '' );
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertSame( [], self::provider()->delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] ) ) );
	}

	public function test_delivery_ids_is_empty_when_dadata_answers_an_empty_set_and_the_miss_is_cached_for_a_day(): void {
		$this->stub_fixture_response( 'findById-delivery-street-level-id-gives-nothing' );
		$record = self::dadata_record( [ 'city_fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ] );

		$this->assertSame( [], self::provider()->delivery_ids( $record ) );
		$this->assertSame( [ DAY_IN_SECONDS ], array_values( $this->transient_ttls ) );

		// Cached: the second ask is free, and still empty.
		$this->assertSame( [], self::provider()->delivery_ids( $record ) );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_delivery_ids_caches_an_answer_for_a_week_and_serves_the_second_call_from_the_cache(): void {
		$this->stub_fixture_response( 'findById-delivery-novosibirsk-by-fias' );
		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] );

		$first = self::provider()->delivery_ids( $record );

		$this->assertSame( [ WEEK_IN_SECONDS ], array_values( $this->transient_ttls ) );

		// A cache hit must not need the token, the network, or even a stub.
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertSame( $first, self::provider()->delivery_ids( $record ) );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_delivery_ids_cache_is_keyed_by_the_queried_id(): void {
		$this->stub_fixture_response( 'findById-delivery-novosibirsk-by-fias' );

		self::provider()->delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] ) );
		self::provider()->delivery_ids( self::dadata_record( [ 'city_fias_id' => '2763c110-cb8b-416a-9dac-ad28a55b4402' ] ) );

		$this->assertSame( 2, $this->http_calls );
		$this->assertCount( 2, $this->transients );
	}

	public function test_delivery_ids_throws_a_provider_exception_on_an_http_failure_and_does_not_cache_it(): void {
		$this->stub_http_response( 500, '' );
		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] );

		try {
			self::provider()->delivery_ids( $record );
			$this->fail( 'Location_Provider_Exception expected.' );
		} catch ( Location_Provider_Exception $exception ) {
			$this->assertInstanceOf( \Woodev_API_Exception::class, $exception->getPrevious() );
		}

		$this->assertTrue( $this->failure_was_logged( 'delivery_ids' ) );
		$this->assertSame( [], $this->transients, 'a failure must retry on the next call, never calcify' );
	}

	public function test_delivery_ids_throws_a_provider_exception_for_an_unreadable_answer(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x"}]}' );

		$this->expectException( Location_Provider_Exception::class );
		self::provider()->delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] ) );
	}

	// -------------------------------------------------------------------------
	// Location_Service::get_delivery_ids()
	// -------------------------------------------------------------------------

	/**
	 * A real service whose provider lookup is pinned — the registry is `final`, and
	 * collecting it for real drags in the settings machinery this test is not about.
	 *
	 * @param array<string, Location_Provider> $providers Registered providers by id.
	 */
	private function service( array $providers ): Location_Service {
		return new class(
			$providers,
			Location_Provider_Registry::instance(),
			Mockery::mock( Customer_Location_Store::class ),
			Mockery::mock( Location_Resolution_Cache::class )
		) extends Location_Service {

			/** @var array<string, Location_Provider> */
			private array $pinned;

			public function __construct( array $pinned, Location_Provider_Registry $registry, Customer_Location_Store $store, Location_Resolution_Cache $cache ) {
				parent::__construct( $registry, $store, $cache );
				$this->pinned = $pinned;
			}

			public function get_registered_provider( string $provider_id ): ?Location_Provider {
				return $this->pinned[ $provider_id ] ?? null;
			}
		};
	}

	public function test_service_hands_the_record_to_the_registered_dadata_provider(): void {
		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] );

		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( true );
		$provider->shouldReceive( 'delivery_ids' )->once()->with( $record )->andReturn( [ 'cdek_id' => '270' ] );

		$this->assertSame( [ 'cdek_id' => '270' ], $this->service( [ 'dadata' => $provider ] )->get_delivery_ids( $record ) );
	}

	public function test_service_is_empty_when_dadata_is_not_configured(): void {
		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( false );
		$provider->shouldNotReceive( 'delivery_ids' );

		$this->assertSame(
			[],
			$this->service( [ 'dadata' => $provider ] )->get_delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] ) )
		);
	}

	public function test_service_is_empty_when_dadata_is_not_registered(): void {
		$this->assertSame(
			[],
			$this->service( [] )->get_delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] ) )
		);
	}

	public function test_service_is_empty_for_a_record_another_provider_produced(): void {
		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldNotReceive( 'is_configured' );
		$provider->shouldNotReceive( 'delivery_ids' );

		$this->assertSame(
			[],
			$this->service( [ 'dadata' => $provider ] )->get_delivery_ids( self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ], 'cdek' ) )
		);
	}

	public function test_service_never_reaches_dadata_for_a_non_russian_record(): void {
		$provider = Mockery::mock( Dadata_Provider::class )->makePartial();
		$provider->shouldReceive( 'is_configured' )->andReturn( true );

		Functions\expect( 'wp_safe_remote_request' )->never();

		$record = self::dadata_record( [ 'city_fias_id' => 'relation:1746396' ], 'dadata', 'KZ' );

		$this->assertSame( [], $this->service( [ 'dadata' => $provider ] )->get_delivery_ids( $record ) );
	}

	public function test_service_lets_a_transport_failure_through(): void {
		$record = self::dadata_record( [ 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ] );

		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( true );
		$provider->shouldReceive( 'delivery_ids' )->andThrow( new Location_Provider_Exception( 'down' ) );

		$this->expectException( Location_Provider_Exception::class );
		$this->service( [ 'dadata' => $provider ] )->get_delivery_ids( $record );
	}
}
