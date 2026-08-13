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

// Task 14: handle_admin_locate_request()'s own `WC_Geolocation::get_ip_address()`
// fallback needs this double — same stub RestRateLimitTraitTest already uses (see
// that file's own require for the full rationale).
if ( ! class_exists( '\\WC_Geolocation' ) ) {
	require_once __DIR__ . '/wc-geolocation-stub.php';
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
	private bool $country_supported;

	/**
	 * Task 13: {@see self::provider_for_list()}'s own return value — a
	 * SEPARATE fake from {@see self::$provider} (the `/suggest`/`/select`
	 * seam's own resolution), since `/list` resolves through
	 * {@see Location_Service::provider_for_list()}, a genuinely different
	 * D15-adjacent chain. Defaults to `null` — every pre-existing test in this
	 * file never touches `/list` and is unaffected.
	 *
	 * @var Location_Provider|null
	 */
	private ?Location_Provider $list_provider;

	/** @var array<int, string|null> */
	public array $provider_for_list_calls = [];

	/**
	 * Optional level => provider map (D15 gate fix, block PR-B test seam).
	 * When set, {@see self::provider_for_level()} resolves THROUGH this map
	 * instead of always returning the single {@see self::$provider} — lets a
	 * test simulate the D15 chain resolving a DIFFERENT provider (with its
	 * own, independently-configured `get_countries()`) per level, exactly
	 * the shape the real {@see Location_Service::provider_for_level()} chain
	 * produces. `null` (the default) preserves the original single-provider
	 * behaviour every pre-existing test in this file relies on.
	 *
	 * @var array<string, Location_Provider>|null
	 */
	private ?array $providers_by_level;

	/**
	 * Simulates {@see Location_Service::is_country_supported()}'s LEVEL-BLIND
	 * (`$level === null`) branch when {@see self::$providers_by_level} is set
	 * — i.e. what the ACTIVE provider alone would answer, the exact call
	 * shape the pre-fix controller used. Only meaningful together with
	 * {@see self::$providers_by_level}: it is what a regression test asserts
	 * the controller must NO LONGER fall back to.
	 *
	 * @var Location_Provider|null
	 */
	private ?Location_Provider $active_provider_for_level_blind_check;

	/** @var array<int, array{0: Location_Record, 1: bool}> */
	public array $set_calls = [];

	/** @var array<int, string> */
	public array $provider_for_level_calls = [];

	/** @var array<int, array{0: string, 1: string|null}> */
	public array $is_country_supported_calls = [];

	/**
	 * Task 14: {@see self::supports_locate()}'s own return value, and
	 * {@see self::locate()}'s own return value/spy — a SEPARATE pair from the
	 * `/suggest`/`/select`/`/list` fakes above, since the admin-only
	 * `/default-locality/locate` route resolves through these two
	 * {@see Location_Service} methods directly, not through `$provider`.
	 *
	 * @var bool
	 */
	private bool $supports_locate;

	/** @var Location_Record|null */
	private ?Location_Record $locate_result;

	/** @var array<int, string> */
	public array $locate_calls = [];

	/**
	 * @param array<string, Location_Provider>|null $providers_by_level                    Optional level => provider map — see {@see self::$providers_by_level}.
	 * @param Location_Provider|null                 $active_provider_for_level_blind_check See {@see self::$active_provider_for_level_blind_check}.
	 * @param Location_Provider|null                 $list_provider                         Task 13: {@see self::provider_for_list()}'s return value.
	 * @param bool                                    $supports_locate                       Task 14: {@see self::supports_locate()}'s own return value.
	 * @param Location_Record|null                   $locate_result                         Task 14: {@see self::locate()}'s own return value.
	 */
	public function __construct(
		bool $active = true,
		?Location_Provider $provider = null,
		?array $customer_record = null,
		bool $persist_result = true,
		bool $country_supported = true,
		?array $providers_by_level = null,
		?Location_Provider $active_provider_for_level_blind_check = null,
		?Location_Provider $list_provider = null,
		bool $supports_locate = false,
		?Location_Record $locate_result = null
	) {
		$this->active                                = $active;
		$this->provider                               = $provider;
		$this->customer_record                        = $customer_record;
		$this->persist_result                         = $persist_result;
		$this->country_supported                      = $country_supported;
		$this->providers_by_level                     = $providers_by_level;
		$this->active_provider_for_level_blind_check = $active_provider_for_level_blind_check;
		$this->list_provider                          = $list_provider;
		$this->supports_locate                        = $supports_locate;
		$this->locate_result                          = $locate_result;
	}

	public function supports_locate(): bool {
		return $this->supports_locate;
	}

	public function locate( string $ip ): ?Location_Record {
		$this->locate_calls[] = $ip;

		return $this->locate_result;
	}

	public function provider_for_list( ?string $country = null ): ?Location_Provider {
		$this->provider_for_list_calls[] = $country;

		return $this->list_provider;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
		$this->provider_for_level_calls[] = $level;

		if ( null !== $this->providers_by_level ) {
			return $this->providers_by_level[ $level ] ?? null;
		}

		return $this->provider;
	}

	public function get_customer_record(): ?array {
		return $this->customer_record;
	}

	public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
		$this->set_calls[] = [ $record, $implicit ];

		return $this->persist_result;
	}

	/**
	 * Without a {@see self::$providers_by_level} map, mirrors the original
	 * fixed-answer fake exactly (level-blind — the pre-existing behaviour
	 * every unchanged test in this file relies on). WITH the map, resolves
	 * the SAME level-specific provider {@see self::provider_for_level()}
	 * itself would return (or, for a `null` `$level`,
	 * {@see self::$active_provider_for_level_blind_check}) and checks the
	 * (normalized) country against THAT provider's OWN `get_countries()` —
	 * i.e. it genuinely exercises the D15 gate fix rather than returning a
	 * canned boolean, so a test using the map is proving the controller
	 * passes `$level` through correctly (and that doing so changes the
	 * answer versus the old level-blind call), not merely that this fake was
	 * told to say "true".
	 */
	public function is_country_supported( string $country, ?string $level = null ): bool {
		$this->is_country_supported_calls[] = [ $country, $level ];

		if ( null === $this->providers_by_level ) {
			return $this->country_supported;
		}

		$provider = null !== $level
			? ( $this->providers_by_level[ $level ] ?? null )
			: $this->active_provider_for_level_blind_check;

		if ( null === $provider ) {
			return false;
		}

		return in_array( strtoupper( trim( $country ) ), $provider->get_countries(), true );
	}
}

/**
 * Configurable fake provider: a closure decides what `suggest()` returns (or
 * throws), and every call is spied so tests can assert the exact query/scope
 * the controller built. `$countries` (D15 gate fix, block PR-B) lets a test
 * give a "chosen" and a "fallback" fake DIFFERENT country coverage — the
 * default `[ 'RU' ]` preserves every pre-existing call site unchanged.
 */
final class Location_Controller_Fake_Provider extends Abstract_Location_Provider {

	/** @var callable */
	private $suggest_callback;

	/** @var string[] */
	private array $countries;

	/** @var array<int, array{0: string, 1: Location_Scope}> */
	public array $suggest_calls = [];

	/**
	 * @param callable $suggest_callback Decides suggest()'s return value (or throws).
	 * @param string[] $countries        ISO-3166 alpha-2 codes this fake covers.
	 */
	public function __construct( callable $suggest_callback, array $countries = [ 'RU' ] ) {
		$this->suggest_callback = $suggest_callback;
		$this->countries        = $countries;
	}

	public function get_id(): string {
		return 'fake';
	}

	public function get_name(): string {
		return 'Fake';
	}

	public function get_countries(): array {
		return $this->countries;
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
 * Task 13: a {@see Location_Controller_Fake_Provider} sibling that DOES
 * override `list_localities()` — kept as a SEPARATE class rather than a
 * conditional branch inside the one above, so reflection-derived capability
 * discovery ({@see Abstract_Location_Provider::get_capabilities()}) correctly
 * reports `list` present only for instances that genuinely need it; a
 * conditionally-no-op override on the shared class would report the
 * capability for every pre-existing test too, which is not what any of them
 * intend to exercise.
 */
final class Location_Controller_Fake_List_Provider extends Abstract_Location_Provider {

	/** @var callable */
	private $list_callback;

	/** @var string[] */
	private array $countries;

	/** @var array<int, Location_Scope> */
	public array $list_calls = [];

	public function __construct( callable $list_callback, array $countries = [ 'RU' ] ) {
		$this->list_callback = $list_callback;
		$this->countries     = $countries;
	}

	public function get_id(): string {
		return 'fake-list';
	}

	public function get_name(): string {
		return 'Fake List';
	}

	public function get_countries(): array {
		return $this->countries;
	}

	protected function declare_suggest_levels(): array {
		return Location_Record::LEVELS;
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function list_localities( Location_Scope $scope ): array {
		$this->list_calls[] = $scope;

		return ( $this->list_callback )( $scope );
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
		// Task 14: check_admin_permission()'s own denial status, and
		// handle_admin_locate_request()'s WC_Geolocation fallback path.
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
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

	/**
	 * P2 review finding (the other half): a well-formed but UNSUPPORTED
	 * country must degrade the same way "no provider for this level" already
	 * does — 200 + empty — WITHOUT ever reaching the provider, so an
	 * unsupported-country request never consumes upstream quota. A malformed
	 * country keeps its own dedicated 400 (build_scope's own validation),
	 * unaffected by this check.
	 */
	public function test_suggest_unsupported_country_returns_empty_200_without_calling_the_provider(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, false );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'US' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
		$this->assertCount( 0, $provider->suggest_calls, 'an unsupported country must never reach the provider' );
	}

	public function test_suggest_supported_country_still_reaches_the_provider(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, $provider, null, true, true );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'RU' ] );
		$ctrl->handle_suggest_request( $request );

		$this->assertCount( 1, $provider->suggest_calls );
	}

	public function test_suggest_a_malformed_country_still_returns_400_not_the_unsupported_degradation(): void {
		$provider = new Location_Controller_Fake_Provider( static fn() => [] );
		// country_supported=false must NOT be why this 400s — is_country_supported()
		// itself degrades to false for malformed input too, but build_scope()'s own
		// format validation must win and return 400 before that check is even reached.
		$service = new Location_Controller_Fake_Service( true, $provider, null, true, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_REGION, 'country' => 'not-a-code' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $provider->suggest_calls );
	}

	// -------------------------------------------------------------------
	// /suggest — D15 gate fix (block PR-B): the country check must be gated
	// by the provider that ACTUALLY serves the requested level (chosen, or
	// the D15 fallback), never by the active provider unconditionally — both
	// wrong directions, pinned with independently-countried fake providers.
	// -------------------------------------------------------------------

	/**
	 * False-suppression direction: the chosen provider does not cover "address"
	 * at all (only the fallback resolves for that level), and the fallback
	 * covers a country ("BY") the chosen provider does not. The request must
	 * still reach the fallback and return its suggestions — gating against the
	 * ACTIVE provider's own country list would have wrongly suppressed this.
	 */
	public function test_suggest_reaches_the_fallback_for_a_country_only_the_fallback_covers(): void {
		$suggested = Location_Record::from_array(
			[
				'key'         => 'fallback:by-1',
				'provider_id' => 'fallback',
				'level'       => Location_Record::LEVEL_ADDRESS,
				'country'     => 'BY',
				'label'       => 'Минск',
			]
		);
		$fallback = new Location_Controller_Fake_Provider( static fn() => [ $suggested ], [ 'RU', 'BY' ] );

		$service = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			[ Location_Record::LEVEL_ADDRESS => $fallback ]
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мин', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'BY' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['suggestions'], 'a country the resolved fallback covers must not be suppressed' );
		$this->assertCount( 1, $fallback->suggest_calls, 'the provider that actually serves this level must be called' );
		$this->assertSame(
			[ [ 'BY', Location_Record::LEVEL_ADDRESS ] ],
			$service->is_country_supported_calls,
			'the country check must be made WITH the requested level, not level-blind'
		);
	}

	/**
	 * False-admission direction: the level's resolved provider (the fallback)
	 * does NOT cover the requested country, even though the ACTIVE (chosen)
	 * provider elsewhere in the chain DOES — `$chosen` stands in for what the
	 * pre-fix controller's level-blind `is_country_supported( $country )` call
	 * would have consulted, and it would have wrongly admitted this request.
	 * The request must degrade to empty WITHOUT ever reaching the resolved
	 * provider — gating against a provider that happens to cover the country,
	 * rather than the one that actually resolved for this level, would have
	 * wasted upstream quota on a lookup that cannot succeed.
	 */
	public function test_suggest_never_reaches_a_provider_for_a_country_it_does_not_cover_even_when_resolved_for_the_level(): void {
		$chosen   = new Location_Controller_Fake_Provider( static fn() => [], [ 'RU', 'KZ' ] );
		$fallback = new Location_Controller_Fake_Provider( static fn() => [ /* would-be suggestions */ ], [ 'RU' ] );

		$service = new Location_Controller_Fake_Service(
			true,
			null,
			null,
			true,
			true,
			[ Location_Record::LEVEL_ADDRESS => $fallback ],
			$chosen
		);
		$ctrl = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'KZ' ] );
		$result  = $ctrl->handle_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
		$this->assertCount( 0, $fallback->suggest_calls, 'a country the resolved provider does not cover must never reach it' );
		$this->assertCount( 0, $chosen->suggest_calls, 'the active provider is never the one dispatched to for this level' );
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

	// -------------------------------------------------------------------
	// /list (Task 13; spec D7) — level enum, malformed country 400, no
	// provider -> 404 (NOT /suggest's 200+empty), happy path, provider
	// exception -> 502, `provider` param never read.
	// -------------------------------------------------------------------

	public function test_list_rejects_an_unknown_level(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_list_a_malformed_country_returns_400(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'not-a-code' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->provider_for_list_calls, 'a malformed country must never even reach provider_for_list()' );
	}

	/**
	 * The deliberate asymmetry with `/suggest`: no provider anywhere in the
	 * D15-adjacent `list` chain resolves for this (well-formed) country ->
	 * 404, never `/suggest`'s 200+empty (see handle_list_request()'s own
	 * docblock for why).
	 */
	public function test_list_no_provider_resolves_returns_404_not_200_empty(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null ); // list_provider = null
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_list_happy_path_returns_shaped_localities(): void {
		$record = Location_Record::from_array(
			[
				'key'         => 'fake-list:mo',
				'provider_id' => 'fake-list',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => '<b>Москва</b>',
			]
		);
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['localities'] );
		$this->assertFalse( $result['truncated'] );

		$locality = $result['localities'][0];
		$this->assertSame( 'fake-list:mo', $locality['key'] );
		$this->assertSame( Location_Record::LEVEL_SETTLEMENT, $locality['level'] );
		$this->assertStringContainsString( '&lt;b&gt;', $locality['label'], 'label must be escaped, same as /suggest' );

		// The record must round-trip UNTOUCHED, same D12/D5 contract as /suggest.
		$round_tripped = Location_Record::from_array( $locality['record'] );
		$this->assertSame( $record->key(), $round_tripped->key() );

		$this->assertCount( 1, $provider->list_calls );
	}

	// -------------------------------------------------------------------
	// /list — PR #304 review finding 5: an unbounded enumeration is capped,
	// an optional `limit` narrows it further (clamped), and the response is
	// honest about truncation rather than silently cutting.
	// -------------------------------------------------------------------

	/**
	 * @param int $count how many fake records the fixture provider returns.
	 *
	 * @return Location_Record[]
	 */
	private function many_records( int $count ): array {
		$records = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$records[] = Location_Record::from_array(
				[
					'key'         => 'fake-list:' . $i,
					'provider_id' => 'fake-list',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'label'       => 'City ' . $i,
				]
			);
		}

		return $records;
	}

	public function test_list_caps_the_response_at_the_hard_limit_and_reports_truncation(): void {
		// One MORE than the hard cap — the exact neighbouring value a mutant
		// removing/loosening the cap would fail against.
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 501 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 500, $result['localities'], 'the response must never exceed LIST_HARD_CAP' );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_list_exactly_at_the_hard_cap_is_not_reported_truncated(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 500 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 500, $result['localities'] );
		$this->assertFalse( $result['truncated'], 'the provider handed back exactly the cap, nothing was actually cut' );
	}

	public function test_list_limit_arg_narrows_the_response_below_the_hard_cap(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 20 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => 5 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 5, $result['localities'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * A client cannot use `limit` to ask for MORE than the hard cap — the
	 * mutant this pins: `min( $limit, self::LIST_HARD_CAP )` reverted to a
	 * bare `$limit` would let this request through at 100000 records.
	 */
	public function test_list_limit_arg_above_the_hard_cap_is_clamped_to_it(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 501 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => 100000 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 500, $result['localities'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_list_limit_arg_zero_or_negative_falls_back_to_the_hard_cap(): void {
		$provider = new Location_Controller_Fake_List_Provider( fn() => $this->many_records( 10 ) );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'limit' => -5 ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertCount( 10, $result['localities'], 'a non-positive limit is not a valid narrowing — falls back to the hard cap' );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * PR #304 review's own test-gap finding: nothing previously pinned
	 * `LIST_RATE_LIMIT_MAX` itself, or even proved the limiter is wired into
	 * `/list` at all — every other `/list` test in this file goes through
	 * {@see Location_Controller_Probe}, which BYPASSES the limiter entirely.
	 * This test uses the REAL {@see Location_Controller} (no probe) with the
	 * real rate-limit storage stubbed (mirrors `RestRateLimitTraitTest`'s own
	 * fixture setup), so it fails both against a mutant that unhooks the
	 * limiter from `/list` and against a mutant that changes the budget away
	 * from 60 (the 61st call is the neighbouring value that pins the exact
	 * number, not merely "some limit exists").
	 */
	public function test_list_rate_limit_is_pinned_at_the_real_budget(): void {
		$store = [];

		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller( $service ); // the REAL controller — rate limiting genuinely runs.

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );

		for ( $i = 0; $i < 60; $i++ ) {
			$result = $ctrl->handle_list_request( $request );
			$this->assertNotInstanceOf( \WP_Error::class, $result, "request {$i} (1-based " . ( $i + 1 ) . ') must still be within the 60/min budget' );
		}

		$result = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result, 'the 61st request must be the one that trips the limiter' );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_list_never_reads_a_client_supplied_provider_param(): void {
		$provider = new Location_Controller_Fake_List_Provider( static fn() => [] );
		$service  = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'provider' => 'cdek' ]
		);
		$ctrl->handle_list_request( $request );

		$this->assertCount( 1, $service->provider_for_list_calls );
	}

	public function test_list_provider_exception_returns_502(): void {
		$provider = new Location_Controller_Fake_List_Provider(
			static function () {
				throw new \RuntimeException( 'upstream boom' );
			}
		);
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_list_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_list_within_matching_current_record_narrows_the_scope(): void {
		$parent   = $this->region_record( 'dadata:region-1' );
		$captured = null;
		$provider = new Location_Controller_Fake_List_Provider(
			static function ( Location_Scope $scope ) use ( &$captured ) {
				$captured = $scope;

				return [];
			}
		);
		$service = new Location_Controller_Fake_Service( true, null, [ 'record' => $parent, 'implicit' => false, 'saved_at' => 0 ], true, true, null, null, $provider );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request(
			[ 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU', 'within' => 'dadata:region-1' ]
		);
		$ctrl->handle_list_request( $request );

		$this->assertNotNull( $captured );
		$this->assertTrue( $captured->has_parent() );
		$this->assertSame( $parent, $captured->parent_record() );
	}

	// -------------------------------------------------------------------
	// check_admin_permission() (Task 14) — capability gate for the two
	// admin-only /default-locality/* routes.
	// -------------------------------------------------------------------

	public function test_check_admin_permission_rejects_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$result = $ctrl->check_admin_permission( new WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_check_admin_permission_accepts_with_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$ctrl = new Location_Controller_Probe( new Location_Controller_Fake_Service() );

		$result = $ctrl->check_admin_permission( new WP_REST_Request() );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------
	// /default-locality/suggest (Task 14) — the admin picker's own search;
	// shares perform_suggest() with the public /suggest route, so this only
	// spot-checks the shared behaviour still holds through the admin entry
	// point rather than re-proving every case LocationControllerTest already
	// covers for handle_suggest_request() above.
	// -------------------------------------------------------------------

	public function test_admin_suggest_rejects_an_unknown_level(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => 'galaxy', 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_admin_suggest_happy_path_returns_shaped_suggestions(): void {
		$record   = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
		$provider = new Location_Controller_Fake_Provider( static fn() => [ $record ] );
		$service  = new Location_Controller_Fake_Service( true, $provider );
		$ctrl     = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_SETTLEMENT, 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $result['suggestions'] );
		$this->assertSame( 'dadata:fias-1', $result['suggestions'][0]['key'] );
	}

	public function test_admin_suggest_no_provider_for_level_returns_empty_200(): void {
		$service = new Location_Controller_Fake_Service( true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'q' => 'Мос', 'level' => Location_Record::LEVEL_ADDRESS, 'country' => 'RU' ] );
		$result  = $ctrl->handle_admin_suggest_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result );
	}

	// -------------------------------------------------------------------
	// /default-locality/locate (Task 14) — the admin picker's geo-IP preview.
	// -------------------------------------------------------------------

	public function test_admin_locate_returns_404_when_the_active_provider_has_no_locate_capability(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, false );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->locate_calls, 'locate() must never even be called once the capability check fails' );
	}

	public function test_admin_locate_happy_path_returns_shaped_location(): void {
		$record  = Location_Record::from_array(
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Москва',
			]
		);
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $record );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ '203.0.113.9' ], $service->locate_calls, 'the EXPLICIT ip param must be used verbatim, not overridden by the request IP' );
		$this->assertSame( 'dadata:fias-1', $result['location']['key'] );
	}

	public function test_admin_locate_returns_null_location_as_200_not_an_error(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, null );
		$ctrl    = new Location_Controller_Probe( $service );

		$request = new WP_REST_Request( [ 'ip' => '203.0.113.9' ] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'location' => null ], $result );
	}

	public function test_admin_locate_falls_back_to_the_request_ip_when_no_ip_param_is_given(): void {
		$record  = $this->record();
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $record );
		$ctrl    = new Location_Controller_Probe( $service );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$request = new WP_REST_Request( [] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ '198.51.100.7' ], $service->locate_calls );
	}

	/**
	 * `get_client_ip()` (the rate-limit trait's own helper) deliberately falls
	 * back to the literal string `'unknown'` rather than `''` — this route
	 * must NOT reuse it for this reason: handing `'unknown'` to a provider's
	 * `locate()` as though it were a real IP would be worse than refusing.
	 */
	public function test_admin_locate_returns_400_when_no_ip_can_be_determined_at_all(): void {
		$service = new Location_Controller_Fake_Service( true, null, null, true, true, null, null, null, true, $this->record() );
		$ctrl    = new Location_Controller_Probe( $service );

		unset( $_SERVER['REMOTE_ADDR'] ); // WC_Geolocation stub falls back to this.

		$request = new WP_REST_Request( [] );
		$result  = $ctrl->handle_admin_locate_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 0, $service->locate_calls );
	}
}
