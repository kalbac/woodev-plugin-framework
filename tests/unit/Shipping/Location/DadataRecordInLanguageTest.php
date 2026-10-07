<?php
/**
 * Unit tests for the same locality in another language (#1152): the optional
 * `$language` of `Dadata_Api_Client::find_by_id_address()`, the cached
 * `Dadata_Provider::record_in_language()` and the `Location_Service` façade a
 * carrier adapter calls.
 *
 * The fixtures `findById-address-khimki-ru.json` and `suggest-address-khimki-en.json`
 * are reconstructed from the values measured on the rig (s159 R1152, 07.10.2026) —
 * see their `note` fields; they are not raw captures.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Location\Customer_Location_Store;
use Woodev\Framework\Shipping\Location\Locality_Key;
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
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::record_in_language
 * @covers \Woodev\Framework\Shipping\Location\Location_Service::get_record_in_language
 */
final class DadataRecordInLanguageTest extends TestCase {

	private const KHIMKI_FIAS = 'd76255c8-3173-4db5-a39b-badd3ebdf851';

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

		$this->last_request    = null;
		$this->http_calls      = 0;
		$this->transients      = [];
		$this->transient_ttls  = [];
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
	 * The English (transliterated) record a customer under an `en` locale holds —
	 * built the way {@see Dadata_Provider} maps a suggestion.
	 */
	private static function english_khimki(): Location_Record {
		$row  = self::fixture( 'suggest-address-khimki-en' )['response']['body']['suggestions'][0];
		$data = $row['data'];

		return Location_Record::from_array(
			[
				'key'         => 'dadata:' . $data['fias_id'],
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'region'      => [ 'name' => $data['region'], 'type' => $data['region_type'] ],
				'settlement'  => [ 'name' => $data['city'], 'type' => $data['city_type'] ],
				'label'       => $row['value'],
				'lat'         => $data['geo_lat'],
				'lon'         => $data['geo_lon'],
				'raw'         => $data,
			]
		);
	}

	private static function foreign_record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'cdek:44',
				'provider_id' => 'cdek',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Moscow',
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
	 * A provider whose client has the locale seam pinned to English — the case the
	 * accessor exists for: the explicit `ru` must win over it.
	 */
	private static function provider(): Dadata_Provider {
		return new class extends Dadata_Provider {

			protected function make_client( string $token, string $secret ): Dadata_Api_Client {
				return new class( $token, $secret ) extends Dadata_Api_Client {

					protected function current_locale(): string {
						return 'en_US';
					}
				};
			}
		};
	}

	// -------------------------------------------------------------------------
	// Dadata_Provider::record_in_language()
	// -------------------------------------------------------------------------

	public function test_it_asks_find_by_id_address_for_the_records_fias_id_with_an_explicit_ru(): void {
		$this->stub_fixture_response( 'findById-address-khimki-ru' );

		self::provider()->record_in_language( self::english_khimki(), 'ru' );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/address', $this->last_request['url'] );
		$this->assertSame(
			[
				'query'    => self::KHIMKI_FIAS,
				'language' => 'ru',
			],
			json_decode( (string) $this->last_request['args']['body'], true ),
			'the explicit language beats the en_US locale the client would otherwise send'
		);
	}

	public function test_it_returns_the_same_locality_spelled_in_russian(): void {
		$this->stub_fixture_response( 'findById-address-khimki-ru' );
		$english = self::english_khimki();

		$russian = self::provider()->record_in_language( $english, 'ru' );

		$this->assertInstanceOf( Location_Record::class, $russian );
		$this->assertSame( $english->key(), $russian->key(), 'identity does not move with the language' );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $russian->level() );
		$this->assertSame( 'Химки', $russian->settlement()['name'] );
		$this->assertSame( 'Московская', $russian->region()['name'] );
		$this->assertSame( 'Московская обл, г Химки', $russian->label() );
		$this->assertSame( 'Khimki', $english->settlement()['name'], 'the original record is untouched' );
		$this->assertSame( self::KHIMKI_FIAS, $russian->raw()['city_fias_id'] );
		$this->assertSame( 55.888755, $russian->lat() );
		$this->assertSame( $english->lat(), $russian->lat() );
		$this->assertSame( $english->lon(), $russian->lon() );
	}

	public function test_a_found_record_is_cached_for_a_week_per_language_and_the_second_call_is_free(): void {
		$this->stub_fixture_response( 'findById-address-khimki-ru' );
		$english = self::english_khimki();

		$first = self::provider()->record_in_language( $english, 'ru' );

		$this->assertSame( [ WEEK_IN_SECONDS ], array_values( $this->transient_ttls ) );
		$this->assertSame( 1, $this->http_calls );

		// A cache hit must not need the network.
		Functions\expect( 'wp_safe_remote_request' )->never();

		$second = self::provider()->record_in_language( $english, 'ru' );

		$this->assertEquals( $first, $second );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_the_cache_is_keyed_by_language_and_by_id(): void {
		$this->stub_fixture_response( 'findById-address-khimki-ru' );
		$english = self::english_khimki();

		self::provider()->record_in_language( $english, 'ru' );
		self::provider()->record_in_language( $english, 'en' );

		$other = Location_Record::from_array(
			[
				'key'         => 'dadata:5f290be7-0000-4000-8000-000000000001',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Mytishchi',
			]
		);
		self::provider()->record_in_language( $other, 'ru' );

		$this->assertSame( 3, $this->http_calls );
		$this->assertCount( 3, $this->transients );
	}

	public function test_a_genuine_not_found_is_cached_for_a_day_and_answers_null(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		$english = self::english_khimki();

		$this->assertNull( self::provider()->record_in_language( $english, 'ru' ) );
		$this->assertSame( [ DAY_IN_SECONDS ], array_values( $this->transient_ttls ) );

		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertNull( self::provider()->record_in_language( $english, 'ru' ) );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_a_transport_failure_throws_the_retryable_exception_and_is_not_cached(): void {
		$this->stub_http_response( 500, '' );
		$english = self::english_khimki();

		try {
			self::provider()->record_in_language( $english, 'ru' );
			$this->fail( 'Location_Provider_Exception expected.' );
		} catch ( Location_Provider_Exception $exception ) {
			$this->assertInstanceOf( \Woodev_API_Exception::class, $exception->getPrevious() );
		}

		$this->assertTrue( $this->failure_was_logged( 'record_in_language' ) );
		$this->assertSame( [], $this->transients, 'a failure must retry on the next call, never calcify as a miss' );

		// …and it does retry.
		$this->stub_fixture_response( 'findById-address-khimki-ru' );

		$this->assertSame( 'Химки', self::provider()->record_in_language( $english, 'ru' )->settlement()['name'] );
	}

	public function test_an_unreadable_answer_throws_and_is_not_cached(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x"}]}' );

		try {
			self::provider()->record_in_language( self::english_khimki(), 'ru' );
			$this->fail( 'Location_Provider_Exception expected.' );
		} catch ( Location_Provider_Exception $exception ) {
			$this->assertSame( [], $this->transients );
		}
	}

	public function test_a_record_another_provider_produced_answers_null_without_a_request(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertNull( self::provider()->record_in_language( self::foreign_record(), 'ru' ) );
	}

	public function test_a_derived_key_answers_null_without_a_request(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$record = Location_Record::from_array(
			[
				'key'         => Locality_Key::derive( 'dadata', [ 'city' => 'Khimki', 'region' => 'Moskovskaya' ] ),
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Khimki',
			]
		);

		$this->assertNull( self::provider()->record_in_language( $record, 'ru' ) );
	}

	public function test_an_unsupported_language_answers_null_without_a_request(): void {
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertNull( self::provider()->record_in_language( self::english_khimki(), 'de' ) );
	}

	public function test_an_unconfigured_provider_answers_null_without_a_request(): void {
		$this->set_token( '' );
		Functions\expect( 'wp_safe_remote_request' )->never();

		$this->assertNull( self::provider()->record_in_language( self::english_khimki(), 'ru' ) );
	}

	// -------------------------------------------------------------------------
	// Location_Service::get_record_in_language()
	// -------------------------------------------------------------------------

	/**
	 * A real service whose provider lookup is pinned — see DadataDeliveryIdsTest.
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

	public function test_service_defaults_to_russian_and_hands_the_record_to_the_dadata_provider(): void {
		$english = self::english_khimki();
		$russian = Location_Record::from_array( array_merge( $english->to_array(), [ 'label' => 'Московская обл, г Химки' ] ) );

		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( true );
		$provider->shouldReceive( 'record_in_language' )->once()->with( $english, 'ru' )->andReturn( $russian );

		$this->assertSame( $russian, $this->service( [ 'dadata' => $provider ] )->get_record_in_language( $english ) );
	}

	public function test_service_passes_an_explicit_language_through(): void {
		$english = self::english_khimki();

		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( true );
		$provider->shouldReceive( 'record_in_language' )->once()->with( $english, 'en' )->andReturn( null );

		$this->assertNull( $this->service( [ 'dadata' => $provider ] )->get_record_in_language( $english, 'en' ) );
	}

	public function test_service_answers_null_when_dadata_is_not_configured(): void {
		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( false );
		$provider->shouldNotReceive( 'record_in_language' );

		$this->assertNull( $this->service( [ 'dadata' => $provider ] )->get_record_in_language( self::english_khimki() ) );
	}

	public function test_service_answers_null_when_dadata_is_not_registered(): void {
		$this->assertNull( $this->service( [] )->get_record_in_language( self::english_khimki() ) );
	}

	public function test_service_answers_null_for_a_record_a_provider_that_cannot_do_it_produced(): void {
		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldNotReceive( 'is_configured' );
		$provider->shouldNotReceive( 'record_in_language' );

		$this->assertNull( $this->service( [ 'dadata' => $provider ] )->get_record_in_language( self::foreign_record() ) );
	}

	public function test_service_lets_a_transport_failure_through(): void {
		$provider = Mockery::mock( Dadata_Provider::class );
		$provider->shouldReceive( 'is_configured' )->andReturn( true );
		$provider->shouldReceive( 'record_in_language' )->andThrow( new Location_Provider_Exception( 'down' ) );

		$this->expectException( Location_Provider_Exception::class );
		$this->service( [ 'dadata' => $provider ] )->get_record_in_language( self::english_khimki() );
	}
}
