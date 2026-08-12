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

	/**
	 * @param bool                                                             $active           is_active() return value.
	 * @param array<string, bool>                                              $supported_levels level => whether SOME configured provider serves it.
	 * @param array{record: Location_Record, implicit: bool, saved_at: int}|null $customer        get_customer_record() return value.
	 * @param string[]                                                          $countries        countries is_country_supported() reports true for.
	 */
	public function __construct( bool $active, array $supported_levels, ?array $customer, array $countries ) {
		$this->active           = $active;
		$this->supported_levels = $supported_levels;
		$this->customer         = $customer;
		$this->countries        = $countries;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_record(): ?array {
		return $this->customer;
	}

	public function is_country_supported( string $country ): bool {
		return in_array( $country, $this->countries, true );
	}

	public function provider_for_level( string $level ): ?Location_Provider {
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
		$this->assertSame( [ 'region' => true, 'settlement' => true, 'address' => false ], $location['levels'] );
		$this->assertNull( $location['current'] );
		$this->assertFalse( $location['implicit'] );
		$this->assertIsString( $location['mode'] );
	}

	public function test_location_countries_is_the_intersection_of_wc_countries_and_provider_support(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU', 'BY' ] );
		$fields  = Checkout_Fields::from_array( [] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU', 'FR' ], $service ) )->build( $fields );

		// 'BY' is provider-supported but not a WC selling country here, so it must
		// not appear; 'FR' is a WC country the provider does not cover.
		$this->assertSame( [ 'RU' ], $config['location']['countries'] );
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
}
