<?php
/**
 * Tests for Checkout_Config — JS-safe config emitter.
 *
 * Verifies that build() strips all PHP callables/secrets from the emitted
 * array, evaluates takeover_condition predicates per-country, and produces
 * the expected endpoint/nonce/fields shape.
 *
 * Task 9 (location-provider layer, 2026-08-12 plan) extends this with the
 * `location` config block: endpoints/nonce/countries/mode/levels/current/
 * implicit, built from a {@see Location_Service} collaborator. Absent when no
 * service is injected or the layer is inactive; shaped per spec D15 (levels
 * report ONLY which levels a configured provider serves, never which
 * provider) and D4 (no token/secret may ever reach the serialized config).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';

/**
 * A minimal fake {@see Location_Service}: every method Checkout_Config's
 * location block reads is overridden with a directly-controlled fixture
 * value, WITHOUT touching the real registry/session/option machinery — same
 * "each test builds exactly the shape it needs" discipline as
 * `Location_Service_Fake_Provider` in `LocationServiceTest`. The parent
 * constructor is never called, so none of Location_Service's own
 * collaborators need to exist.
 */
final class Checkout_Config_Fake_Location_Service extends Location_Service {

	private bool $active;

	/** @var array<string, bool> */
	private array $supported_levels;

	/** @var array{record: Location_Record, implicit: bool, saved_at: int}|null */
	private ?array $customer;

	/** @var string[] */
	private array $countries;

	/** @var string Task 13: get_field_mode() return value. */
	private string $mode;

	/** @var string[] Task 13/issue #294: countries owns_region_states() reports true for. */
	private array $owned_region_countries;

	/**
	 * @param bool                                                             $active                 is_active() return value.
	 * @param array<string, bool>                                              $supported_levels       level => whether SOME configured provider serves it,
	 *                                                                                                   for EVERY country in $countries (this fake does not
	 *                                                                                                   model per-country level variance — LocationServiceTest
	 *                                                                                                   covers that against the real Dadata_Provider).
	 * @param array{record: Location_Record, implicit: bool, saved_at: int}|null $customer              get_customer_record() return value.
	 * @param string[]                                                          $countries              the layer's own supported-country set — what
	 *                                                                                                   get_supported_countries() (D15 gate fix, block
	 *                                                                                                   PR-B: the UNION across the chain, no longer a
	 *                                                                                                   single active-provider list) reports.
	 * @param string                                                            $mode                   Task 13: get_field_mode() return value; defaults to
	 *                                                                                                   the pre-Task-13 hardcoded constant so every existing
	 *                                                                                                   call site is unaffected.
	 * @param string[]                                                          $owned_region_countries Task 13/issue #294: countries owns_region_states()
	 *                                                                                                   reports `true` for.
	 */
	public function __construct(
		bool $active,
		array $supported_levels,
		?array $customer,
		array $countries,
		string $mode = Location_Provider_Registry::MODE_TYPEAHEAD,
		array $owned_region_countries = []
	) {
		$this->active                 = $active;
		$this->supported_levels       = $supported_levels;
		$this->customer                = $customer;
		$this->countries               = $countries;
		$this->mode                    = $mode;
		$this->owned_region_countries = $owned_region_countries;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_record(): ?array {
		return $this->customer;
	}

	public function is_country_supported( string $country, ?string $level = null ): bool {
		return in_array( $country, $this->countries, true );
	}

	public function get_supported_countries(): array {
		return $this->countries;
	}

	public function get_field_mode(): string {
		return $this->mode;
	}

	public function owns_region_states( string $country, array $final_states ): bool {
		return in_array( $country, $this->owned_region_countries, true );
	}

	public function get_levels_for_country( string $country ): array {
		$levels = [];

		foreach ( Location_Record::LEVELS as $level ) {
			$levels[ $level ] = ! empty( $this->supported_levels[ $level ] );
		}

		return $levels;
	}

	public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
		if ( empty( $this->supported_levels[ $level ] ) ) {
			return null;
		}

		return new class() extends Abstract_Location_Provider {
			public function get_id(): string {
				return 'fake-provider-should-never-leak';
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
				return [];
			}
		};
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Config
 */
class CheckoutConfigTest extends TestCase {

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	public function test_emit_excludes_callables_and_includes_field_shape(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->set_type( 'select' )->set_source( static fn() => [], 'options' )
				->set_takeover_condition( static fn( $c ) => in_array( $c['country'] ?? '', [ 'RU', 'BY' ], true ) )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'NONCE', [ 'RU', 'BY', 'FR' ] ) )->build( $fields );

		$field = $config['fields']['billing_state'];
		$this->assertArrayNotHasKey( 'source', $field );
		$this->assertArrayNotHasKey( 'takeover_condition', $field );
		$this->assertArrayNotHasKey( 'sanitize_callback', $field );
		$this->assertSame( 'options', $field['source_kind'] );
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( [ 'RU' => true, 'BY' => true, 'FR' => false ], $config['takeover']['billing_state'] );
		$this->assertSame( 'NONCE', $config['nonce'] );
		$this->assertSame( 'https://x/wp-json/woodev/v1/shipping/checkout/carrier/field-source', $config['endpoint'] );
	}

	public function test_field_without_takeover_has_no_takeover_map_entry(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );
		$this->assertArrayNotHasKey( 'billing_city', $config['takeover'] );
	}

	// -------------------------------------------------------------------------
	// location_level on the per-field emitted shape — Task 9
	// -------------------------------------------------------------------------

	public function test_emits_location_level_for_a_location_kind_field(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( 'location', $config['fields']['billing_city']['source_kind'] );
		$this->assertSame( 'settlement', $config['fields']['billing_city']['location_level'] );
	}

	public function test_location_level_is_null_for_a_non_location_field(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertNull( $config['fields']['carrier_pvz']['location_level'] );
	}

	// -------------------------------------------------------------------------
	// location block — absent when inactive/unwired
	// -------------------------------------------------------------------------

	public function test_location_block_absent_when_no_service_is_injected(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertArrayNotHasKey( 'location', $config );
	}

	public function test_location_block_absent_when_the_layer_is_inactive(): void {
		$service = new Checkout_Config_Fake_Location_Service( false, [], null, [] );
		$fields  = Checkout_Fields::from_array( [] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( $fields );

		$this->assertArrayNotHasKey( 'location', $config );
	}

	// -------------------------------------------------------------------------
	// location block — present and shaped when active
	// -------------------------------------------------------------------------

	public function test_location_block_present_and_shaped_when_active(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => false ],
			null,
			[ 'RU', 'BY' ]
		);
		$fields = Checkout_Fields::from_array( [] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'NONCE', [ 'RU', 'BY', 'FR' ], $service ) )->build( $fields );

		$this->assertArrayHasKey( 'location', $config );
		$location = $config['location'];

		$this->assertSame( 'https://x/wp-json/woodev/v1/location/suggest', $location['endpoints']['suggest'] );
		$this->assertSame( 'https://x/wp-json/woodev/v1/location/select', $location['endpoints']['select'] );
		// Same wp_rest nonce as the top-level config — the one
		// Location_Controller::check_select_permission() actually verifies (Task 8),
		// not a second, differently-named one.
		$this->assertSame( 'NONCE', $location['nonce'] );
		$this->assertSame( $config['nonce'], $location['nonce'] );
		// levels is a MAP keyed by country (D15 amendment follow-up) — one entry
		// per country in $location['countries'], never a single flat per-level
		// answer (a single "current country" would go stale the moment the
		// customer switches country client-side, with no round-trip to refresh).
		$this->assertSame(
			[
				'RU' => [ 'region' => true, 'settlement' => true, 'address' => false ],
				'BY' => [ 'region' => true, 'settlement' => true, 'address' => false ],
			],
			$location['levels']
		);
		$this->assertNull( $location['current'] );
		$this->assertFalse( $location['implicit'] );
		$this->assertIsString( $location['mode'] );
	}

	/**
	 * D15 amendment follow-up: `levels` must genuinely vary PER COUNTRY when
	 * the resolved provider's own per-country support does — exercised through
	 * the REAL Location_Service + the real bundled Dadata_Provider (the fake
	 * above cannot model this; DaData is the only provider in this codebase
	 * whose get_suggest_levels() actually depends on country).
	 */
	public function test_location_levels_map_varies_per_country_for_the_real_dadata_provider(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return 'woodev_location_token' === $name ? 'tok' : $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active() );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU', 'AM' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$levels = $config['location']['levels'];

		$this->assertSame( [ 'region' => true, 'settlement' => true, 'address' => true ], $levels['RU'] );
		$this->assertSame( [ 'region' => true, 'settlement' => true, 'address' => false ], $levels['AM'], 'AM is GeoNames-tier: no address-level suggest' );
	}

	public function test_location_countries_is_the_intersection_of_wc_countries_and_provider_support(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU', 'BY' ] );
		$fields  = Checkout_Fields::from_array( [] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU', 'FR' ], $service ) )->build( $fields );

		// 'BY' is provider-supported but not a WC selling country here, so it must
		// not appear; 'FR' is a WC country the provider does not cover.
		$this->assertSame( [ 'RU' ], $config['location']['countries'] );
	}

	/**
	 * D15 gate fix (block PR-B), exercised end-to-end through the REAL
	 * {@see Location_Service} + provider registry (not the simplified fake
	 * above, which cannot model two DIFFERENTLY-countried providers along the
	 * chain): the chosen provider serves region/settlement only, in `RU`; the
	 * bundled DaData fallback serves every level (including "address"), with
	 * its own country list widened to `RU`+`BY`. The `countries` block must
	 * be the UNION across the whole chain — `BY`, covered only by the
	 * fallback at "address", must still surface — never just the active
	 * provider's own list, and no provider id may leak into the config
	 * either way.
	 */
	public function test_location_countries_is_the_union_across_the_d15_chain_not_just_the_active_provider(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();

		$chosen = new class() extends Abstract_Location_Provider {
			public function get_id(): string {
				return 'city-dict';
			}

			public function get_name(): string {
				return 'City Dict';
			}

			public function get_countries(): array {
				return [ 'RU' ];
			}

			protected function declare_suggest_levels(): array {
				return [ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ];
			}

			public function suggest( string $query, Location_Scope $scope ): array {
				return [];
			}
		};

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $chosen ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $tag ) {
					return [ $chosen ];
				}
				if ( Dadata_Provider::FILTER_COUNTRIES === $tag ) {
					return [ 'RU', 'BY' ]; // the fallback widens its own coverage.
				}

				return $default;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				if ( 'woodev_location_active_provider' === $name ) {
					return 'city-dict';
				}
				if ( 'woodev_location_token' === $name ) {
					return 'tok'; // configures the bundled fallback.
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active() );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU', 'BY', 'FR' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		// RU: the chosen provider covers it directly. BY: only the fallback
		// (resolved at "address") covers it — must still surface. FR: nobody
		// in the chain covers it.
		$countries = $config['location']['countries'];
		sort( $countries );
		$this->assertSame( [ 'BY', 'RU' ], $countries );

		$serialized = (string) json_encode( $config );
		$this->assertStringNotContainsString( 'city-dict', $serialized, 'the chosen provider id must never leak' );
		$this->assertStringNotContainsString( 'dadata', $serialized, 'the fallback provider id must never leak' );
	}

	// -------------------------------------------------------------------------
	// current / implicit — from the customer store
	// -------------------------------------------------------------------------

	private function customer_record( bool $implicit ): array {
		return [
			'record'   => Location_Record::from_array(
				[
					'key'         => 'dadata:fias-1',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			),
			'implicit' => $implicit,
			'saved_at' => 1234567890,
		];
	}

	public function test_current_is_null_when_the_customer_has_no_record(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertNull( $config['location']['current'] );
		$this->assertFalse( $config['location']['implicit'] );
	}

	public function test_current_is_populated_and_shaped_exactly_like_the_select_endpoint_response(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( false ),
			[ 'RU' ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		// Shape: { key, level } — byte-for-byte the same shape
		// Location_Controller::handle_select_request() returns under 'current'
		// (Task 8), deliberately, so the client can update from either response
		// without the two ever disagreeing.
		$this->assertSame( [ 'key' => 'dadata:fias-1', 'level' => 'settlement' ], $config['location']['current'] );
		$this->assertFalse( $config['location']['implicit'] );
	}

	public function test_implicit_flag_is_reported_true_when_the_stored_record_is_a_default_guess(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( true ),
			[ 'RU' ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config['location']['implicit'] );
	}

	// -------------------------------------------------------------------------
	// D15 — the client learns WHICH LEVELS are served, never WHICH PROVIDER
	// -------------------------------------------------------------------------

	public function test_serialized_config_never_names_a_provider_id(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( false ),
			[ 'RU' ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		// The fake provider's own id is a distinctive string chosen specifically so
		// this assertion is meaningful, not merely "matches by accident". Only the
		// customer record's own persisted key (which the client already round-tripped
		// itself via /select) may legitimately carry a provider prefix.
		$serialized = (string) json_encode( $config );
		$this->assertStringNotContainsString( 'fake-provider-should-never-leak', $serialized );
	}

	// -------------------------------------------------------------------------
	// D4 — no token/secret may ever reach the serialized config (real provider)
	// -------------------------------------------------------------------------

	/**
	 * Uses the REAL provider registry + the REAL bundled Dadata_Provider (not the
	 * fake fixture) with distinctive token/secret values, so this test proves the
	 * absence of those exact strings anywhere in the serialized output — not just
	 * the absence of a specific array key, which would miss a value nested
	 * somewhere the test author did not think to check (Task 9 instructions).
	 */
	public function test_serialized_config_never_leaks_the_dadata_token_or_clean_secret(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();

		// Deliberately LOW-entropy placeholders. The first version of this test used
		// realistic hex-bearing values, and gitleaks' `generic-api-key` rule flagged
		// the secret (entropy 4.0) — a test proving a credential does not leak was
		// itself failing the credential scanner. Uniqueness is what this assertion
		// needs (the values are grepped for in the serialized config); entropy is
		// not, so it is spent nowhere.
		$token  = 'token-value-that-must-never-reach-the-client';
		$secret = 'secret-value-that-must-never-reach-the-client';

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) use ( $token, $secret ) {
				if ( 'woodev_location_token' === $name ) {
					return $token;
				}
				if ( 'woodev_location_clean_secret' === $name ) {
					return $secret;
				}

				return $default;
			}
		);

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertInstanceOf( Dadata_Provider::class, $registry->get_providers()[ Location_Provider_Registry::DEFAULT_PROVIDER_ID ] );

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active(), 'the bundled DaData provider must be active+configured for this test to be meaningful' );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$serialized = (string) json_encode( $config );
		$this->assertStringNotContainsString( $token, $serialized );
		$this->assertStringNotContainsString( $secret, $serialized );
		// D15: the client learns WHICH LEVELS are served, never WHICH provider.
		$this->assertStringNotContainsString( 'dadata', $serialized );
	}

	// -------------------------------------------------------------------------
	// §4.4 — field-presence variants produce a coherent config, absent links skipped
	// -------------------------------------------------------------------------

	public function test_field_presence_variant_region_city_address(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_state' )->source_location( 'region' )->to_array(),
			Field::create( 'billing_city' )->source_location( 'settlement' )->to_array(),
			Field::create( 'billing_address_1' )->source_location( 'address' )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( 'region', $config['fields']['billing_state']['location_level'] );
		$this->assertSame( 'settlement', $config['fields']['billing_city']['location_level'] );
		$this->assertSame( 'address', $config['fields']['billing_address_1']['location_level'] );
	}

	public function test_field_presence_variant_city_and_address_only(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->source_location( 'settlement' )->to_array(),
			Field::create( 'billing_address_1' )->source_location( 'address' )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertArrayNotHasKey( 'billing_state', $config['fields'] );
		$this->assertSame( 'settlement', $config['fields']['billing_city']['location_level'] );
		$this->assertSame( 'address', $config['fields']['billing_address_1']['location_level'] );
	}

	public function test_field_presence_variant_city_only(): void {
		$fields = Checkout_Fields::from_array( [
			Field::create( 'billing_city' )->source_location( 'settlement' )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertArrayNotHasKey( 'billing_state', $config['fields'] );
		$this->assertArrayNotHasKey( 'billing_address_1', $config['fields'] );
		$this->assertSame( 'settlement', $config['fields']['billing_city']['location_level'] );
	}

	// -------------------------------------------------------------------------
	// i18n — the empty-result message the typeahead shows (operator, s70)
	// -------------------------------------------------------------------------

	public function test_location_block_carries_the_no_results_message(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		// The client must never carry this literal itself — a customer-facing string
		// belongs to the translated, filterable server side like every other one.
		$this->assertArrayHasKey( 'i18n', $config['location'] );
		$this->assertIsString( $config['location']['i18n']['noResults'] );
		$this->assertNotSame( '', $config['location']['i18n']['noResults'] );
	}

	public function test_the_no_results_message_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_i18n' === $hook ? [ 'noResults' => 'Ничего нет' ] : $value;
			}
		);

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'Ничего нет', $config['location']['i18n']['noResults'] );
	}

	public function test_location_block_carries_the_not_persisted_message(): void {
		// #295 finding 1 (Task 13): the client-side consumer for an honest
		// `persisted: false` /select response reads this string — see
		// `location-cascade.js`'s own `showNotPersistedNotice()`.
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertIsString( $config['location']['i18n']['notPersisted'] );
		$this->assertNotSame( '', $config['location']['i18n']['notPersisted'] );
	}

	public function test_no_token_or_secret_leaks_into_the_i18n_block(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$serialized = (string) json_encode( $config['location']['i18n'] );

		$this->assertStringNotContainsStringIgnoringCase( 'token', $serialized );
		$this->assertStringNotContainsStringIgnoringCase( 'secret', $serialized );
	}

	// -------------------------------------------------------------------------
	// Task 13 — the `list` REST endpoint always rides along
	// -------------------------------------------------------------------------

	public function test_location_block_exposes_the_list_endpoint(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'https://x/wp-json/woodev/v1/location/list', $config['location']['endpoints']['list'] );
	}

	// -------------------------------------------------------------------------
	// Task 13 — `mode` reads the real store setting (via Location_Service),
	// no longer the hardcoded 'typeahead' constant
	// -------------------------------------------------------------------------

	public function test_mode_reads_from_the_location_service(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true ],
			null,
			[ 'RU' ],
			Location_Provider_Registry::MODE_RELATED_LIST
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( Location_Provider_Registry::MODE_RELATED_LIST, $config['location']['mode'] );
	}

	public function test_mode_defaults_to_typeahead(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $config['location']['mode'] );
	}

	// -------------------------------------------------------------------------
	// Task 13 / issue #294 — the region arbitration: WC()->countries->get_states()
	// is the FINAL authority, read here, after every woocommerce_states filter.
	// -------------------------------------------------------------------------

	/**
	 * Builds a {@see Checkout_Config} whose overridable `wc_states()` seam
	 * (see that method's own docblock) answers from a fixed `country => states`
	 * map, WITHOUT ever touching Brain Monkey's global `WC()` function table.
	 *
	 * Deliberately NOT `Functions\when( 'WC' )->justReturn( ... )`: Brain
	 * Monkey/Patchwork instruments a mocked function for the REST OF THE PHP
	 * PROCESS once any test stubs it — measured directly, a first version of
	 * this fixture broke 21 unrelated tests elsewhere in the suite the moment
	 * `composer test:unit` ran the full run in one process (every other test
	 * relying on `function_exists( 'WC' ) === false` started seeing it
	 * defined-but-unmocked). The protected-method-override seam
	 * `Checkout_Config::wc_states()` exists specifically so this fixture never
	 * needs to touch that global table at all.
	 *
	 * @param array<string, array<string, string>> $states_by_country Country code => WC states array.
	 *
	 * @return Checkout_Config
	 */
	private function config_with_states(
		string $plugin_id,
		string $rest_base,
		string $nonce,
		array $countries,
		?Location_Service $service,
		array $states_by_country
	): Checkout_Config {
		return new class( $plugin_id, $rest_base, $nonce, $countries, $service, $states_by_country ) extends Checkout_Config {
			private array $states_by_country;

			public function __construct(
				string $plugin_id,
				string $rest_base,
				string $nonce,
				array $countries,
				?Location_Service $service,
				array $states_by_country
			) {
				parent::__construct( $plugin_id, $rest_base, $nonce, $countries, $service );
				$this->states_by_country = $states_by_country;
			}

			protected function wc_states( string $country ): array {
				return $this->states_by_country[ $country ] ?? [];
			}
		};
	}

	/**
	 * The honest baseline: a country with NO registered WC states at all keeps
	 * `region` exactly as the D15 chain answered (true) — this is what every
	 * one of the nine location-layer countries measures as, on the rig, today
	 * (issue #294 decision comment's own measurement).
	 */
	public function test_region_stays_ours_when_wc_has_no_states_for_the_country(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU' ] );
		$config  = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service, [] )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config['location']['levels']['RU']['region'] );
	}

	/**
	 * The #294 rule's load-bearing case: a NON-EMPTY WC state list for a
	 * country the D15 chain wanted "region" for, that this layer did NOT
	 * inject itself (owns_region_states() false) — `region` must be reported
	 * as NOT ours, and the conflict must be reported via `_doing_it_wrong()`
	 * exactly once.
	 */
	public function test_region_reported_not_ours_when_wc_has_foreign_states_and_doing_it_wrong_fires_once(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'BY' ] );
		$config  = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'BY' ], $service, [ 'BY' => [ 'MIN' => 'Минск' ] ] )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['levels']['BY']['region'], 'a country WC already has native/foreign states for is never ours' );
	}

	/**
	 * The layer's OWN related-list injection is never treated as a conflict
	 * with itself: `owns_region_states()` true means the SAME non-empty state
	 * list this method reads is the layer's own doing, so `_doing_it_wrong()`
	 * must NOT fire, even though the raw states-present check alone would
	 * look identical to the foreign-conflict case above.
	 */
	public function test_doing_it_wrong_does_not_fire_for_the_layers_own_related_list_injection(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			null,
			[ 'RU' ],
			Location_Provider_Registry::MODE_RELATED_LIST,
			[ 'RU' ] // owns_region_states( 'RU' ) === true.
		);
		$config = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service, [ 'RU' => [ 'test-list:mo' => 'Московская область' ] ] )
			->build( Checkout_Fields::from_array( [] ) );

		// The state list is non-empty (our own injection), so the client must
		// still be told "region" is not a typeahead target here — WC already
		// renders a native <select> — but WITHOUT the conflict warning.
		$this->assertFalse( $config['location']['levels']['RU']['region'] );
	}

	/**
	 * A country the D15 chain does NOT want "region" for at all must never
	 * trigger the conflict warning, regardless of what WC's states look like —
	 * there is nothing for this layer to have wanted here.
	 */
	public function test_doing_it_wrong_does_not_fire_when_the_chain_never_wanted_region(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => false, 'settlement' => true ], null, [ 'US' ] );
		$config  = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'US' ], $service, [ 'US' => [ 'CA' => 'California' ] ] )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['levels']['US']['region'] );
	}

	/**
	 * Degradation: when WC() is unavailable at all (a caller/test with no
	 * WooCommerce loaded), the region arbitration must never fatal — it
	 * degrades to "no states known", trusting the D15 chain's own answer
	 * unchanged (the exact pre-Task-13 behavior every other test in this file
	 * without a WC() stub already relies on).
	 */
	public function test_region_arbitration_never_fatals_when_wc_is_unavailable(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config['location']['levels']['RU']['region'] );
	}

	/**
	 * PR #304 review finding 1 (CRITICAL): `WC_Countries::get_states( $cc )`
	 * returns `false` — not `[]` — when the country key is absent
	 * (`includes/class-wc-countries.php`), and `(array) false === [ 0 => false ]`,
	 * which is NON-empty. Every other test in this file exercises `wc_states()`
	 * either through the `config_with_states()` Probe subclass's OVERRIDE
	 * (never running the real method body at all) or through the
	 * `function_exists( 'WC' ) === false` degradation branch above (never
	 * reaching the `WC()->countries->get_states()` call either) — neither one
	 * runs the real cast/filter logic this test pins.
	 *
	 * Isolated in its own process (`@runInSeparateProcess`, same idiom as
	 * `FrameworkResolverTest`/`BootstrapRegistrationTest` elsewhere in this
	 * suite): Brain Monkey must actually DECLARE a global `WC()` function to
	 * stub it, and PHP cannot un-declare a function once declared — stubbing
	 * `WC()` here would otherwise permanently poison every other test in the
	 * suite relying on `function_exists( 'WC' ) === false` (measured directly,
	 * see `wc_states()`'s own docblock: a first version of that method calling
	 * `WC()` inline broke 21 unrelated tests the moment `composer test:unit`
	 * ran the whole suite in one process).
	 *
	 * The mutant this pins: reverting `wc_states()`'s
	 * `array_filter( (array) WC()->countries->get_states( $country ) )` back to
	 * the bare `(array) WC()->countries->get_states( $country )` cast turns
	 * `false` into `[ 0 => false ]` — non-empty — so `states_present` would be
	 * `true` and this assertion would flip to `false`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_states_degrades_a_false_get_states_result_to_states_present_false(): void {
		$countries = new class() {
			/**
			 * @param string $country ISO-3166 alpha-2 country code.
			 *
			 * @return false Mirrors `WC_Countries::get_states()`'s own contract
			 *               for a country nothing is registered under.
			 */
			public function get_states( string $country ) {
				return false;
			}
		};
		$wc = new class( $countries ) {
			public $countries;

			public function __construct( $countries ) {
				$this->countries = $countries;
			}
		};

		Functions\when( 'WC' )->justReturn( $wc );

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU' ] );
		// The REAL Checkout_Config — not the config_with_states() Probe — so
		// wc_states()'s real body (the array_filter/(array) cast) actually runs.
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue(
			$config['location']['levels']['RU']['region'],
			'a WC states read of `false` must degrade to states_present=false, not a false-positive conflict'
		);
	}
}
