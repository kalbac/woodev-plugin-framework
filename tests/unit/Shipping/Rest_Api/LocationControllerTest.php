<?php
/**
 * Tests for Location_Controller — the woodev/v1 location REST routes (Task 8;
 * spec D1, D4, D8, D15).
 *
 * Covers: `q` length boundaries (both sides), `level` enum validation, the
 * "unknown/stale `within` key is treated as absent, not an error" rule, the
 * escaped `label` alongside the untouched round-trippable `record`, the
 * "no provider for this level" and "layer inactive" cases both degrading to
 * `{ suggestions: [] }` (200, never 404/500), a client-supplied `provider`
 * param being silently ignored, `/select`'s malformed-record 400 (nothing
 * written), `/select`'s inactive-layer 404, the nonce permission gate, and the
 * no-token/secret-leak guarantee (D4).
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Rest_Api\Location_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

/**
 * Minimal \WP_REST_Request stand-in — identical shape/rationale to
 * PickupControllerTest's own namespace-scoped double (see that file's
 * docblock for why this is namespace-scoped rather than global).
 */
if ( ! class_exists( __NAMESPACE__ . '\\WP_REST_Request', false ) ) {
	class WP_REST_Request {

		/** @var array<string, mixed> */
		private array $params;

		/** @var array<string, string> */
		private array $headers;

		/**
		 * @param array<string, mixed>  $params  request params.
		 * @param array<string, string> $headers request headers.
		 */
		public function __construct( array $params = [], array $headers = [] ) {
			$this->params  = $params;
			$this->headers = $headers;
		}

		/**
		 * @param string $key param name.
		 *
		 * @return mixed|null
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param string $key header name.
		 *
		 * @return string|null
		 */
		public function get_header( $key ) {
			return $this->headers[ $key ] ?? null;
		}
	}
}

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-location-controller.php';

/**
 * Lightweight {@see Location_Service} test double: overrides the constructor
 * entirely (never calling the parent's, so none of its heavy collaborators —
 * the provider registry singleton, a real Customer_Location_Store — are ever
 * touched) and the four methods Location_Controller actually calls. Mirrors
 * the "Probe subclass" discipline used throughout this codebase
 * (Customer_Location_Store_Probe, Field_Source_Controller_Probe, …) rather
 * than re-proving Location_Service's own internals, which LocationServiceTest
 * already covers exhaustively.
 */
final class Location_Controller_Fake_Service extends Location_Service {

	private bool $active;
	private ?Location_Provider $provider;
	private ?array $customer_record;
	private bool $persist_result;

	/** @var array<int, array{0: Location_Record, 1: bool}> */
	public array $set_calls = [];

	/** @var array<int, string> */
	public array $provider_for_level_calls = [];

	public function __construct(
		bool $active = true,
		?Location_Provider $provider = null,
		?array $customer_record = null,
		bool $persist_result = true
	) {
		$this->active          = $active;
		$this->provider        = $provider;
		$this->customer_record = $customer_record;
		$this->persist_result  = $persist_result;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function provider_for_level( string $level ): ?Location_Provider {
		$this->provider_for_level_calls[] = $level;

		return $this->provider;
	}

	public function get_customer_record(): ?array {
		return $this->customer_record;
	}

	public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
		$this->set_calls[] = [ $record, $implicit ];

		return $this->persist_result;
	}
}

/**
 * Configurable fake provider: a closure decides what `suggest()` returns (or
 * throws), and every call is spied so tests can assert the exact query/scope
 * the controller built.
 */
final class Location_Controller_Fake_Provider extends Abstract_Location_Provider {

	/** @var callable */
	private $suggest_callback;

	/** @var array<int, array{0: string, 1: Location_Scope}> */
	public array $suggest_calls = [];

	public function __construct( callable $suggest_callback ) {
		$this->suggest_callback = $suggest_callback;
	}

	public function get_id(): string {
		return 'fake';
	}

	public function get_name(): string {
		return 'Fake';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return Location_Record::LEVELS;
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		$this->suggest_calls[] = [ $query, $scope ];

		return ( $this->suggest_callback )( $query, $scope );
	}
}

/**
 * Probe bypassing the rate limiter — mirrors Field_Source_Controller_Probe /
 * Pickup_Controller's own probe pattern; the rate-limit MECHANISM itself is
 * exhaustively covered by RestRateLimitTraitTest, not re-proven here.
 */
final class Location_Controller_Probe extends Location_Controller {

	protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
		return false;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Location_Controller
 */
final class LocationControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
		// stubEscapeFunctions() returns the input verbatim; override esc_html so the
		// escaping contract on the top-level `label` is actually exercised.
		Functions\when( 'esc_html' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES );
			}
		);
		Functions\when( 'rest_ensure_response' )->returnArg();
	}

	private function record( string $key = 'dadata:fias-1', string $level = Location_Record::LEVEL_SETTLEMENT ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $key,
				'provider_id' => explode( ':', $key )[0],
				'level'       => $level,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
	}

	private function region_record( string $key = 'dadata:region-1' ): Location_Record {
		return $this->record( $key, Location_Record::LEVEL_REGION );
	}

	// -------------------------------------------------------------------
	// /suggest — `q` length boundaries (BOTH sides — a rejection-only test
	// would pass even with the wrong limit)
	// -------------------------------------------------------------------

	public function test_suggest_rejects_a_query_one_char_under_the_minimum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'a', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_suggest_accepts_a_query_exactly_at_the_minimum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'ab', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
	}

	public function test_suggest_accepts_a_query_exactly_at_the_maximum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$q       = str_repeat( 'a', 128 );
		$request = new WP_REST_Request( [ 'q' => $q, 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_suggest_rejects_a_query_one_char_over_the_maximum(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$q       = str_repeat( 'a', 129 );
		$request = new WP_REST_Request( [ 'q' => $q, 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// /suggest — `level` enum
	// -------------------------------------------------------------------

	public function test_suggest_rejects_an_unknown_level(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// /suggest — happy path: escaped label, untouched round-trippable record
	// -------------------------------------------------------------------

	public function test_suggest_happy_path_returns_shaped_suggestions(): void {
		$record   = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => '<b>Москва</b>',
			]
		);
		$provider = new Location_Controller_Fake_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertSame( 1, count( $result['suggestions'] ) );
		$suggestion = $result['suggestions'][0];

		$this->assertSame( 'dadata:fias-1', $suggestion['key'] );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $suggestion['level'] );
		$this->assertStringContainsString( '&lt;b&gt;', $suggestion['label'], 'top-level label must be escaped' );
		$this->assertStringNotContainsString( '<b>', $suggestion['label'] );

		// The `record` payload must round-trip UNTOUCHED — Location_Record::from_array()
		// must accept it back verbatim (D12/D5's own contract).
		$this->assertSame( $record->to_array(), $suggestion['record'] );
		$round_tripped = Location_Record::from_array( $suggestion['record'] );
		$this->assertSame( $record->key(), $round_tripped->key() );
	}

	public function test_suggest_never_reads_a_client_supplied_provider_param(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		// A request naming a provider must be dispatched through the SAME
		// server-resolved provider regardless — the param is never consulted.
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU', 'provider' => 'cdek' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $service->provider_for_level_calls );
	}

	// -------------------------------------------------------------------
	// /suggest — degradation: no provider for the level, and the whole
	// layer inactive, BOTH collapse to 200 + empty (never 404/500)
	// -------------------------------------------------------------------

	public function test_suggest_no_provider_for_level_returns_empty_200(): void {
		$service = new Location_Controller_Fake_Service( true, null ); // active, but no provider serves this level
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
	}

	public function test_suggest_inactive_layer_returns_empty_200_not_404(): void {
		// Location_Service::provider_for_level() itself returns null while the
		// gate is closed (get_active_provider() returns null) — the fake mirrors
		// that by handing back a null provider regardless of $active.
		$service = new Location_Controller_Fake_Service( false, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
	}

	public function test_suggest_never_fatals_for_an_unsupported_level(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		foreach ( Location_Record::LEVELS as $level ) {
			$request = new WP_REST_Request( [ 'q' => 'ab', 'level' => $level, 'country' => 'RU' ] );
			$result  = $ctrl->handle_suggest_request( $request );

			$this->assertNotInstanceOf( \WP_Error::class, $result, "level \"$level\" must not error when unsupported" );
		}
	}

	// -------------------------------------------------------------------
	// /suggest — `within` narrowing: matches, mismatches, and level-ordering
	// mismatches ALL degrade silently to a country-wide scope — never an error
	// -------------------------------------------------------------------

	public function test_suggest_within_matching_current_record_narrows_the_scope(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent() );
		$this->assertSame( $parent, $captured->parent_record() );
	}

	public function test_suggest_unknown_within_key_is_treated_as_absent_not_an_error(): void {
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		// No customer record at all stored server-side.
		$service = new Location_Controller_Fake_Service( true, $provider, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:some-stale-key' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'a stale within key must never error the field' );
		$this->assertNotNull( $captured );
		$this->assertFalse( $captured->has_parent(), 'an unmatched within key must fall back to a country-wide scope' );
		$this->assertSame( 'RU', $captured->country() );
	}

	public function test_suggest_within_key_mismatching_the_current_record_is_treated_as_absent(): void {
		$stored   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $stored, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		// Client believes the parent is "region-2"; server actually holds "region-1".
		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-2' ]
		);
		$ctrl->handle_suggest_request( $request );

		$this->assertFalse( $captured->has_parent() );
	}

	public function test_suggest_within_key_with_wrong_level_ordering_is_treated_as_absent(): void {
		// The stored "current" record is itself a REGION — narrowing a region-level
		// search by a region parent is nonsensical (no level is shallower than
		// region); Location_Scope::within() refuses it, and the controller must
		// swallow that refusal exactly like an unmatched key.
		$stored   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_Provider(
			static function ( string $q, Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, $provider, [ 'record' => $stored, 'implicit' => false, 'saved_at' => 0 ] );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$result = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $captured->has_parent() );
	}

	// -------------------------------------------------------------------
	// /suggest — no token/secret leak (D4): the controller only ever touches
	// a provider's suggest() RETURN VALUE (Location_Record instances), never
	// its credentials/settings — proven by a fake carrying a "secret" the
	// response must never contain.
	// -------------------------------------------------------------------

	public function test_suggest_response_never_leaks_provider_credentials(): void {
		$secret_holder = new class( 'SECRET-TOKEN-XYZ' ) extends Abstract_Location_Provider {
			private string $token;

			public function __construct( string $token ) {
				$this->token = $token;
			}

			public function get_id(): string {
				return 'fake';
			}

			public function get_name(): string {
				return 'Fake';
			}

			public function get_countries(): array {
				return [ 'RU' ];
			}

			protected function declare_suggest_levels(): array {
				return Location_Record::LEVELS;
			}

			public function suggest( string $query, Location_Scope $scope ): array {
				// The token/secret is NEVER placed into a returned record — this is
				// the real DaData provider's own contract too (its `raw` carries only
				// DaData's own response `data` fields, never the request credentials).
				return [
					Location_Record::from_array(
						[
							'key'         => 'dadata:fias-1',
							'provider_id' => 'dadata',
							'level'       => Location_Record::LEVEL_SETTLEMENT,
							'country'     => 'RU',
							'label'       => 'Москва',
							'raw'         => [ 'value' => 'Москва', 'fias_id' => 'fias-1' ],
						]
					),
				];
			}
		};

		$service = new Location_Controller_Fake_Service( true, $secret_holder );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$json = (string) json_encode( $result );

		$this->assertStringNotContainsString( 'SECRET-TOKEN-XYZ', $json );
	}

	// -------------------------------------------------------------------
	// /select — malformed record: 400, nothing written
	// -------------------------------------------------------------------

	public function test_select_malformed_record_returns_400_and_writes_nothing(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => [ 'level' => 'settlement', 'country' => 'RU' ] ] ); // no key/provider_id
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	public function test_select_non_array_record_returns_400(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => 'not-an-array' ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — layer inactive: 404
	// -------------------------------------------------------------------

	public function test_select_inactive_layer_returns_404(): void {
		$service = new Location_Controller_Fake_Service( false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'record' => $this->record()->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — happy path: stored EXPLICIT, response coherent with the D8
	// client flow (client needs to know it can now fire update_checkout)
	// -------------------------------------------------------------------

	public function test_select_happy_path_stores_explicit_and_returns_current(): void {
		$service = new Location_Controller_Fake_Service( true );
		$ctrl    = new Location_Controller_Probe( $service );

		$record  = $this->record();
		$request = new WP_REST_Request( [ 'record' => $record->to_array() ] );
		$result  = $ctrl->handle_select_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $service->set_calls );
		[ $stored_record, $implicit ] = $service->set_calls[0];
		$this->assertSame( $record->key(), $stored_record->key() );
		$this->assertFalse( $implicit, 'a customer selection through /select must be stored EXPLICIT' );

		$this->assertSame( $record->key(), $result['current']['key'] );
		$this->assertSame( $record->level(), $result['current']['level'] );
		$this->assertTrue( $result['persisted'] );
	}

	public function test_select_round_trips_a_record_suggest_itself_returned(): void {
		// The exact shape /suggest hands back as `suggestions[].record` must be
		// accepted verbatim by /select — the two endpoints must not disagree.
		$suggested_record = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-7',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				'postcode'    => '101000',
				'lat'         => 55.75,
				'lon'         => 37.61,
				'label'       => 'г Москва',
				'raw'         => [ 'city_kladr_id' => '7700000000000' ],
			]
		);

		$suggest_provider = new Location_Controller_Fake_Provider( static fn() => [ $suggested_record ] );
		$suggest_service  = new Location_Controller_Fake_Service( true, $suggest_provider );
		$suggest_ctrl     = new Location_Controller_Probe( $suggest_service );

		$suggest_request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$suggest_result  = $suggest_ctrl->handle_suggest_request( $suggest_request );
		$posted_record   = $suggest_result['suggestions'][0]['record'];

		$select_service = new Location_Controller_Fake_Service( true );
		$select_ctrl    = new Location_Controller_Probe( $select_service );

		$select_request = new WP_REST_Request( [ 'record' => $posted_record ] );
		$select_result  = $select_ctrl->handle_select_request( $select_request );

		$this->assertNotInstanceOf( \WP_Error::class, $select_result );
		$this->assertSame( 'dadata:fias-7', $select_result['current']['key'] );
		$this->assertCount( 1, $select_service->set_calls );
	}

	// -------------------------------------------------------------------
	// /select — nonce permission gate (mirrors Pickup_Controller precedent)
	// -------------------------------------------------------------------

	public function test_check_select_permission_rejects_a_missing_nonce(): void {
		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_select_permission_rejects_an_invalid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'bad-nonce' ] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_check_select_permission_accepts_a_valid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$request = new WP_REST_Request( [], [ 'X-WP-Nonce' => 'good-nonce' ] );
		$result  = $ctrl->check_select_permission( $request );

		$this->assertTrue( $result );
	}
}
