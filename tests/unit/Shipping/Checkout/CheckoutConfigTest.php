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
 * Task 8 (issue #362, design S7) extends `pickup_slot_placements` with a middle
 * precedence step: the framework's `'rate'`-alone default is now itself derived from
 * the store's own `pickup_button_placement` setting ({@see Pickup_Map_Settings}) before
 * the `woodev_pickup_slot_placements` filter gets a chance to override it.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Environment;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
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

	/**
	 * Issue #330 (location-chain design §8): the FULL chain
	 * {@see self::get_customer_chain()} answers — a map `level =>
	 * Location_Record`. Defaults to `null`, in which case
	 * {@see self::get_customer_chain()} derives a ONE-ENTRY chain from
	 * {@see self::$customer} (the pre-existing, single-record fake behaviour
	 * every test predating #330 relies on) — so only a test that actually
	 * needs a MULTI-level chain passes this explicitly.
	 *
	 * @var array<string, Location_Record>|null
	 */
	private ?array $chain_records;

	/** @var string[] */
	private array $countries;

	/** @var string Issue #380: get_field_mode_region() return value. */
	private string $mode_region;

	/** @var string Issue #380: get_field_mode_settlement() return value. */
	private string $mode_settlement;

	/** @var bool Issue #528: is_custom_settlement_allowed() return value. */
	private bool $allow_custom_settlement;

	/** @var array<string, array<int, array{key: string, label: string, level: string, record: array<string, mixed>}>> Issue #530: get_popular_settlements_for_country() return value, keyed by country. */
	private array $popular_settlements;

	/** @var string[] Task 13/issue #294: countries owns_region_states() reports true for. */
	private array $owned_region_countries;

	/** @var string Issue #296: resolve_default_country() return value. */
	private string $default_country;

	/** @var string Issue #536: get_default_locality_policy() return value. */
	private string $default_locality_policy;

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
	 * @param string                                                            $mode_region            Issue #380: get_field_mode_region() return value;
	 *                                                                                                   defaults to typeahead so every existing call site is
	 *                                                                                                   unaffected.
	 * @param string[]                                                          $owned_region_countries Task 13/issue #294: countries owns_region_states()
	 *                                                                                                   reports `true` for.
	 * @param string                                                            $default_country        Issue #296: resolve_default_country() return
	 *                                                                                                   value; defaults to 'RU' so every existing call
	 *                                                                                                   site is unaffected. Fixed here rather than left
	 *                                                                                                   to the REAL method (which reads get_option()) —
	 *                                                                                                   this fake never touches WordPress option state at
	 *                                                                                                   all, mirroring every other method on this class.
	 * @param array<string, Location_Record>|null                              $chain_records           Issue #330: see {@see self::$chain_records}.
	 * @param string|null                                                       $mode_settlement        Issue #380: get_field_mode_settlement() return
	 *                                                                                                   value; `null` (the default) mirrors `$mode_region` —
	 *                                                                                                   the pre-#380 behaviour where a single shared mode
	 *                                                                                                   moved both axes together, so every existing call
	 *                                                                                                   site stays unaffected. A test exercising the two
	 *                                                                                                   axes INDEPENDENTLY passes this explicitly.
	 * @param bool                                                              $allow_custom_settlement Issue #528: is_custom_settlement_allowed() return
	 *                                                                                                   value; defaults to `false`, matching the setting's
	 *                                                                                                   own default, so every existing call site stays
	 *                                                                                                   unaffected.
	 * @param array<string, array>                                             $popular_settlements     Issue #530: get_popular_settlements_for_country()
	 *                                                                                                   return value, keyed by country; defaults to `[]`
	 *                                                                                                   for every country, so every existing call site
	 *                                                                                                   stays unaffected.
	 * @param string                                                            $default_locality_policy Issue #536: get_default_locality_policy() return
	 *                                                                                                   value; defaults to `off`, matching the setting's
	 *                                                                                                   own default, so every existing call site stays
	 *                                                                                                   unaffected (build_location_block() never sends
	 *                                                                                                   `defaultLocality` for `off`).
	 */
	public function __construct(
		bool $active,
		array $supported_levels,
		?array $customer,
		array $countries,
		string $mode_region = Location_Provider_Registry::MODE_TYPEAHEAD,
		array $owned_region_countries = [],
		string $default_country = 'RU',
		?array $chain_records = null,
		?string $mode_settlement = null,
		bool $allow_custom_settlement = false,
		array $popular_settlements = [],
		string $default_locality_policy = Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF
	) {
		$this->active                 = $active;
		$this->supported_levels       = $supported_levels;
		$this->customer                = $customer;
		$this->countries               = $countries;
		$this->mode_region             = $mode_region;
		$this->mode_settlement         = $mode_settlement ?? $mode_region;
		$this->owned_region_countries = $owned_region_countries;
		$this->default_country         = $default_country;
		$this->popular_settlements     = $popular_settlements;
		$this->chain_records            = $chain_records;
		$this->allow_custom_settlement = $allow_custom_settlement;
		$this->default_locality_policy = $default_locality_policy;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function resolve_default_country(): string {
		return $this->default_country;
	}

	public function get_default_locality_policy(): string {
		return $this->default_locality_policy;
	}

	public function get_customer_record( ?string $for_country = null ): ?array {
		return $this->customer;
	}

	/**
	 * Issue #330: mirrors the real {@see Location_Service::get_customer_chain()}
	 * contract — WITHOUT this override, calling `get_customer_chain()` on this
	 * fake would run the REAL method body, which reaches
	 * `$this->customer_store->get_chain()`; `$this->customer_store` is never
	 * set (this fake's constructor never calls the parent's), so that would be
	 * a fatal "call on null" for every test using this fake.
	 *
	 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
	 */
	public function get_customer_chain( ?string $for_country = null ): ?array {
		if ( null !== $this->chain_records ) {
			return [
				'records'  => $this->chain_records,
				'current'  => null !== $this->customer ? $this->customer['record']->level() : '',
				'implicit' => null !== $this->customer ? $this->customer['implicit'] : false,
				'saved_at' => null !== $this->customer ? $this->customer['saved_at'] : 0,
			];
		}

		if ( null === $this->customer ) {
			return null;
		}

		return [
			'records'  => [ $this->customer['record']->level() => $this->customer['record'] ],
			'current'  => $this->customer['record']->level(),
			'implicit' => $this->customer['implicit'],
			'saved_at' => $this->customer['saved_at'],
		];
	}

	public function is_country_supported( string $country, ?string $level = null ): bool {
		return in_array( $country, $this->countries, true );
	}

	public function get_supported_countries(): array {
		return $this->countries;
	}

	public function get_field_mode_region(): string {
		return $this->mode_region;
	}

	public function get_field_mode_settlement(): string {
		return $this->mode_settlement;
	}

	public function is_custom_settlement_allowed(): bool {
		return $this->allow_custom_settlement;
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

	/**
	 * Issue #530: WITHOUT this override, calling get_popular_settlements_for_country()
	 * on this fake would run the REAL method body, which reaches
	 * `$this->registry->get_active_provider()`; `$this->registry` is never set
	 * (this fake's constructor never calls the parent's), so that would be a
	 * fatal "call on null" for every test using this fake — same reasoning as
	 * `get_customer_chain()`'s own override above.
	 */
	public function get_popular_settlements_for_country( string $country ): array {
		return $this->popular_settlements[ $country ] ?? [];
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

	protected function setUp(): void {
		parent::setUp();

		// resolve_pickup_slot_placements() (Task 8, issue #362) now reaches
		// Pickup_Map_Settings::current() for every pickup-slot field, which lazily
		// constructs a real Pickup_Map_Settings through Woodev_Abstract_Settings — stub
		// the WP primitives that path touches, same as CheckoutFieldSettingsTest /
		// CheckoutHandlerEnqueueTest / ShippingSettingsTabTest.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		Shipping_Settings_Tab::reset_for_tests();
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
	// pickup_slot_placements — issue #274 item 3, default narrowed by issue #323
	// -------------------------------------------------------------------------

	/**
	 * Issue #323: the default is ONE placement, `'rate'` — never both at once, which put
	 * two identical «Выбрать пункт выдачи» buttons a few pixels apart in front of the
	 * customer. Both reference plugins ship a single position chosen by a store SETTING
	 * (Yandex `widget_position`, Почта РФ `map_button_place`); neither ever renders twice.
	 */
	public function test_pickup_slot_placements_default_to_the_rate_placement_alone(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [ 'rate' ], $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	/**
	 * The filter still receives the framework's own default as its first argument — a
	 * consumer that wants to ADD the second placement back has to be able to see what it
	 * is starting from. Pins the default at the seam, not only at the output (issue #323).
	 */
	public function test_pickup_slot_placements_filter_receives_the_single_rate_default(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )
			->once()
			->with( [ 'rate' ], 'carrier_pvz', 'carrier' )
			->andReturn( [ 'rate' ] );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertTrue( true );
	}

	/**
	 * Both placements at once stay REACHABLE — the filter is the seam that keeps them
	 * available for a store that genuinely wants them, which is why #323 narrowed the
	 * default rather than deleting the `'review'` placement (issue #323).
	 */
	public function test_pickup_slot_placements_filter_can_still_ask_for_both(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->andReturn( [ 'rate', 'review' ] );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [ 'review', 'rate' ], $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	public function test_pickup_slot_placements_is_empty_for_a_non_pickup_field(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->to_array() ] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [], $config['fields']['billing_city']['pickup_slot_placements'] );
	}

	/**
	 * The extension hook (framework rule: leave a filter even with no consumer yet) — a
	 * site or plugin can REPLACE the framework's default placement with the other one.
	 * Since #323 that is the shape a store wanting the pre-#323 «under the whole list»
	 * position uses, until the carrier-settings screen the operator is designing offers it.
	 */
	public function test_pickup_slot_placements_filter_can_replace_the_default_placement(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )
			->once()
			->with( [ 'rate' ], 'carrier_pvz', 'carrier' )
			->andReturn( [ 'review' ] );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [ 'review' ], $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	public function test_pickup_slot_placements_filter_is_never_invoked_for_a_non_pickup_field(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->never();

		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->to_array() ] );
		( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertTrue( true );
	}

	/**
	 * A malformed filter return (an unrecognised string mixed in) must not reach the browser
	 * verbatim — only `'review'`/`'rate'` ever pass through, and always in that fixed order
	 * regardless of the order the filter itself returned them in.
	 */
	public function test_pickup_slot_placements_filter_return_is_guarded_against_unknown_values(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->andReturn( [ 'rate', 'bogus', 'review' ] );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [ 'review', 'rate' ], $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	/**
	 * A non-array filter return is MALFORMED, never a deliberate "suppress both" —
	 * {@see self::test_pickup_slot_placements_filter_can_explicitly_return_empty} is the
	 * legitimate empty case, and the two must not collapse to the same value (issue #308
	 * item 2 — adversarial review of #274 item 3). `null` is what tells
	 * `checkout-field-classic.js` to apply its OWN mixed-fleet default (`['review']`)
	 * rather than trust a filter that returned nonsense.
	 */
	public function test_pickup_slot_placements_filter_non_array_return_yields_null(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->andReturn( 'not-an-array' );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertNull( $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	/**
	 * The other half of the #308 item 2 fix: a WELL-FORMED empty return — a plugin
	 * deliberately telling the framework it renders its own trigger and wants neither
	 * placement — must reach the browser as a real `[]`, not be upgraded to `null` (which
	 * would make the browser apply the mixed-fleet default and mount a `'review'` trigger
	 * nobody asked for).
	 */
	public function test_pickup_slot_placements_filter_can_explicitly_return_empty(): void {
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->andReturn( [] );

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame( [], $config['fields']['carrier_pvz']['pickup_slot_placements'] );
	}

	// -------------------------------------------------------------------------
	// pickup_slot_placements — the store's own setting as the middle precedence
	// step (Task 8, issue #362, design S7)
	// -------------------------------------------------------------------------

	/**
	 * Resolves `pickup_slot_placements` for a single pickup-slot field, exactly like
	 * every test above. Resets {@see Shipping_Settings_Tab} before EVERY call — not
	 * only once in {@see self::setUp()} — because {@see Pickup_Map_Settings::current()}
	 * reaches the tab singleton, and `Woodev_Setting::get_value()` is a value CACHED at
	 * construction time, never a live option read (gotcha
	 * `woodev-setting-get-value-is-cached-not-a-live-option-read`): a test that changes
	 * the `get_option` stub between two calls needs a fresh `Pickup_Map_Settings`
	 * instance to actually see the new value, and resetting here — inside the helper
	 * itself — is cheaper than repeating the reset at every call site.
	 *
	 * @return string[]|null
	 */
	private function resolve_placements(): ?array {
		Shipping_Settings_Tab::reset_for_tests();

		$fields = Checkout_Fields::from_array(
			[ Field::create( 'carrier_pvz' )->set_type( 'hidden' )->mark_pickup_slot()->to_array() ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		return $config['fields']['carrier_pvz']['pickup_slot_placements'];
	}

	/**
	 * The full three-step precedence chain in one test: the framework's `'rate'`-alone
	 * default, then the store's own `pickup_button_placement` setting overriding it,
	 * then the `woodev_pickup_slot_placements` filter overriding THAT — each step is
	 * meaningless without proving the one before it actually feeds into it, which is
	 * why this is a single sequential test rather than three independent ones.
	 */
	public function test_placement_precedence_default_then_store_setting_then_filter(): void {
		// default
		$this->assertSame( [ 'rate' ], $this->resolve_placements() );

		// store setting
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_pickup_map_pickup_button_placement' === $k ? 'review' : $d );
		$this->assertSame( [ 'review' ], $this->resolve_placements() );

		// filter wins last
		Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->with( [ 'review' ], Mockery::any(), Mockery::any() )->andReturn( [ 'rate', 'review' ] );
		$this->assertSame( [ 'review', 'rate' ], $this->resolve_placements() );
	}

	/**
	 * A stored value that is neither `'rate'` nor `'review'` (a stale constant, a typo
	 * written directly to the option, a future settings version this code does not know
	 * about yet) clamps to the framework's own `'rate'` default on READ — design §7,
	 * same discipline as {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings::effective()}.
	 * The stored option itself is never rewritten; only what THIS request resolves to
	 * falls back.
	 */
	public function test_placement_default_clamps_an_unrecognised_stored_value_to_rate(): void {
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_pickup_map_pickup_button_placement' === $k ? 'bogus' : $d );

		$this->assertSame( [ 'rate' ], $this->resolve_placements() );
	}

	// -------------------------------------------------------------------------
	// field_policy / pickup_method_ids — Task 6, issue #362, spec §4.3
	// -------------------------------------------------------------------------

	public function test_field_policy_defaults_to_all_show_when_no_settings_injected(): void {
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame(
			[ 'address' => 'show', 'postcode' => 'show', 'country' => 'show' ],
			$config['field_policy']
		);
	}

	/**
	 * The `field_policy` block reads through the SAME `Checkout_Field_Settings::effective()`
	 * clamp-on-read contract Task 5 already pins — this test proves Checkout_Config
	 * genuinely calls it (through the injected collaborator) rather than reading the
	 * raw stored option itself.
	 */
	public function test_field_policy_reads_effective_values_from_the_injected_settings_handler(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				return false !== strpos( (string) $name, 'postcode_field' ) ? 'hide_for_pickup' : null;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);

		// Off the block checkout ($block_checkout = false), so hide_for_pickup is offered.
		$settings = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], null, $settings ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'hide_for_pickup', $config['field_policy']['postcode'] );
		$this->assertSame( 'show', $config['field_policy']['address'] );
		$this->assertSame( 'show', $config['field_policy']['country'] );
	}

	public function test_pickup_method_ids_is_empty_when_wc_is_unavailable(): void {
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( [], $config['pickup_method_ids'] );
	}

	// -------------------------------------------------------------------------
	// block_place_order (issue #725) — client-side-only place-order gate flag
	// -------------------------------------------------------------------------

	public function test_block_place_order_defaults_to_true_when_no_settings_injected(): void {
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config['block_place_order'] );
	}

	/**
	 * Reads through the SAME `Checkout_Field_Settings::effective()` contract as
	 * `field_policy` — proves Checkout_Config genuinely calls it (through the
	 * injected collaborator) rather than assuming the default in every case.
	 */
	public function test_block_place_order_reads_the_effective_value_from_the_injected_settings_handler(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				return false !== strpos( (string) $name, 'block_place_order' ) ? false : null;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);

		$settings = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );

		$config_off = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], null, $settings ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config_off['block_place_order'] );

		Functions\when( 'get_option' )->justReturn( null );

		$settings_on = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );

		$config_on = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], null, $settings_on ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config_on['block_place_order'] );
	}

	// -------------------------------------------------------------------------
	// resolve_required() — the `is_pickup_method` sentinel (issue #709)
	// -------------------------------------------------------------------------

	public function test_resolve_required_leaves_a_plain_bool_untouched(): void {
		$this->assertTrue( Checkout_Config::resolve_required( true ) );
		$this->assertFalse( Checkout_Config::resolve_required( false ) );
	}

	public function test_resolve_required_leaves_an_explicit_in_spec_untouched(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ];

		$this->assertSame( $spec, Checkout_Config::resolve_required( $spec ) );
	}

	/**
	 * Without WC() available, `pickup_method_ids()` degrades to `[]` (see the test
	 * above) — this pins that `resolve_required()` genuinely REWRITES the sentinel
	 * operator to a concrete `in` spec rather than leaving it as-is, even when the
	 * derived list happens to be empty.
	 */
	public function test_resolve_required_rewrites_the_sentinel_to_a_concrete_in_spec(): void {
		$resolved = Checkout_Config::resolve_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'is_pickup_method' ] );

		$this->assertSame(
			[ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [] ],
			$resolved
		);
	}

	/**
	 * The sentinel is recognised inside a multi-condition `conditions` array too —
	 * a plugin combining it with e.g. a country check must not have it silently
	 * ignored.
	 */
	public function test_resolve_required_recurses_into_a_multi_condition_spec(): void {
		$resolved = Checkout_Config::resolve_required(
			[
				'relation'   => 'AND',
				'conditions' => [
					[ 'state' => 'chosen_shipping_method', 'operator' => 'is_pickup_method' ],
					[ 'state' => 'country', 'operator' => '=', 'value' => 'RU' ],
				],
			]
		);

		$this->assertSame(
			[ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [] ],
			$resolved['conditions'][0]
		);
		$this->assertSame(
			[ 'state' => 'country', 'operator' => '=', 'value' => 'RU' ],
			$resolved['conditions'][1]
		);
	}

	/**
	 * End-to-end with a REAL pickup method registered through `WC()->shipping()`:
	 * `resolve_required()` — and, through it, `build()` — must publish the SAME id
	 * list `pickup_method_ids()` itself derives, proving decision #1 (one source of
	 * truth) actually reaches a `Pickup_Field`'s omitted-list default and the
	 * browser-bound config in the same step.
	 *
	 * `@runInSeparateProcess`: `Functions\when( 'WC' )` permanently instruments the
	 * global `WC()` function for the rest of the PHP process once used (see
	 * `config_with_states()`'s own docblock above for the 21-test breakage this
	 * caused when first done inline) — isolated here for the identical reason.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_required_and_build_derive_real_ids_from_is_pickup_shipping(): void {
		require_once __DIR__ . '/CheckoutConfigPickupMethodFixture.php';
		require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/presets/class-pickup-field.php';

		$courier = new Checkout_Config_Fake_Shipping_Method( 'test_courier', false );
		$pickup  = new Checkout_Config_Fake_Shipping_Method( 'test_pickup', true );

		$shipping = new class( [ $courier, $pickup ] ) {
			private array $methods;
			public function __construct( array $methods ) {
				$this->methods = $methods;
			}
			public function get_shipping_methods(): array {
				return $this->methods;
			}
		};
		$wc = new class( $shipping ) {
			public $shipping_service;
			public function __construct( $shipping_service ) {
				$this->shipping_service = $shipping_service;
			}
			public function shipping() {
				return $this->shipping_service;
			}
		};

		Functions\when( 'WC' )->justReturn( $wc );

		$this->assertSame( [ 'test_pickup' ], Checkout_Config::pickup_method_ids() );

		$resolved = Checkout_Config::resolve_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'is_pickup_method' ] );
		$this->assertSame( [ 'test_pickup' ], $resolved['value'] );

		// Full round trip: a lazily-declared Pickup_Field publishes the SAME ids to
		// the browser through build() — no plugin ever named 'test_pickup'.
		$fields = Checkout_Fields::from_array( [
			\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create( 'carrier_pickup_point' )->to_array(),
		] );
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ] ) )->build( $fields );

		$this->assertSame(
			[ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'test_pickup' ] ],
			$config['fields']['carrier_pickup_point']['required']
		);
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
		$this->assertIsString( $location['mode']['region'] );
		$this->assertIsString( $location['mode']['settlement'] );
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
		// resolve_default_country() reads wc_get_base_location() (PR #320 review, finding 3) —
		// this test asserts on `levels`/`countries`, never `defaultCountry`, so any well-formed
		// stub is fine here.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		// Issue #530: see the identical comment in
		// test_location_block_default_country_reads_the_real_wc_option_through_the_real_service()
		// for why this mock is needed now that build_location_block() always calls
		// get_popular_settlements_for_country().
		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'all_for_provider' )->andReturn( [] );
		$reflection = new \ReflectionClass( Location_Provider_Registry::class );
		$property   = $reflection->getProperty( 'popular_settlement_store' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $registry, $store );

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
		// resolve_default_country() reads wc_get_base_location() (PR #320 review, finding 3) —
		// this test asserts on `countries`, never `defaultCountry`, so any well-formed stub is
		// fine here.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

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

		// Issue #352 (Variant A): `owners` is spec D15's one DELIBERATE exception — it names
		// providers on purpose, so the client can refuse a foreign-provider record before ever
		// posting it (see `class-checkout-config.php::build_location_block()`'s own `owners`
		// docblock). Every OTHER key must still honour the non-leak guarantee, so the check
		// below runs against the config WITH `owners` stripped out first.
		$without_owners = $config;
		unset( $without_owners['location']['owners'] );
		$serialized = (string) json_encode( $without_owners );
		$this->assertStringNotContainsString( 'city-dict', $serialized, 'the chosen provider id must never leak outside owners' );
		$this->assertStringNotContainsString( 'dadata', $serialized, 'the fallback provider id must never leak outside owners' );

		// The other half of the same fact: `owners` DOES legitimately carry both ids for the
		// levels each provider actually resolves — RU's region/settlement (the chosen provider)
		// and BY's address (the fallback), proving this is a real publication, not an accident.
		$this->assertSame( 'city-dict', $config['location']['owners']['RU']['region'] );
		$this->assertSame( 'dadata', $config['location']['owners']['BY']['address'] );
	}

	// -------------------------------------------------------------------------
	// owners — issue #352's mixed-provider-chain fix (Variant A): per-level provider
	// ownership, byte-consistent with `levels` (owners[c][l] === '' EXACTLY when
	// levels[c][l] === false, for the SAME country/level).
	// -------------------------------------------------------------------------

	public function test_owners_reports_empty_string_for_a_level_no_provider_serves(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => false ],
			null,
			[ 'RU' ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['levels']['RU']['address'] );
		$this->assertSame( '', $config['location']['owners']['RU']['address'] );
		// The SERVED levels carry the real (fake, here) provider id.
		$this->assertSame( 'fake-provider-should-never-leak', $config['location']['owners']['RU']['region'] );
		$this->assertSame( 'fake-provider-should-never-leak', $config['location']['owners']['RU']['settlement'] );
	}

	/**
	 * The #294 arbitration must apply to BOTH maps together (this method's own docblock's
	 * byte-consistency promise): a country whose region is stood down because WooCommerce
	 * already renders a native `<select>` there must report `owners[c].region === ''` too,
	 * even though the D15 chain itself DOES resolve a provider for that level — otherwise the
	 * client could see a non-empty `owners[c].region` for a level `levels[c].region === false`
	 * already told it is not a typeahead target, which `mayEnterChain()` client-side would then
	 * read as "this provider owns region" for a field WooCommerce, not this layer, actually
	 * renders.
	 */
	public function test_owners_region_is_forced_empty_by_the_294_arbitration_together_with_levels(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'BY' ] );
		$config  = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'BY' ], $service, [ 'BY' => [ 'MIN' => 'Минск' ] ] )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['levels']['BY']['region'] );
		$this->assertSame( '', $config['location']['owners']['BY']['region'], 'owners must agree with levels — never name an owner for a level WC already renders natively' );
		// Only `region` is affected — settlement stays the fake provider's, untouched by the
		// arbitration (which is region-specific).
		$this->assertSame( 'fake-provider-should-never-leak', $config['location']['owners']['BY']['settlement'] );
	}

	/**
	 * The #484 exception is exactly ONE case wide (push-review finding). A country the
	 * chain serves no region for must STILL report no region owner, even though no state
	 * list is present to stand the layer down — otherwise `owners` would name a provider
	 * for a level nothing renders, which is the incoherence the #352 rule exists to stop.
	 */
	public function test_owners_region_stays_empty_when_the_chain_serves_no_region_at_all(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => false, 'settlement' => true, 'address' => true ], null, [ 'RU' ] );
		$config  = $this->config_with_states( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service, [] )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['levels']['RU']['region'] );
		$this->assertSame(
			'',
			$config['location']['owners']['RU']['region'],
			'no state list is present, but the chain serves no region either — the owner must stay empty'
		);
	}

	/**
	 * The mixed-chain case issue #352 exists for, end-to-end through the REAL
	 * {@see Location_Service} + provider registry (not the simplified fake above, which
	 * cannot model two DIFFERENTLY-countried providers along the chain — same reasoning as
	 * {@see self::test_location_countries_is_the_union_across_the_d15_chain_not_just_the_active_provider()}):
	 * the chosen provider owns region/settlement, the bundled DaData fallback owns address
	 * alone, for the SAME country. `owners['RU']` must name the RIGHT provider PER LEVEL.
	 */
	public function test_owners_names_different_providers_per_level_in_a_mixed_chain(): void {
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
				return Location_Provider_Registry::FILTER_PROVIDERS === $tag ? [ $chosen ] : $default;
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
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active() );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame(
			[ 'region' => 'city-dict', 'settlement' => 'city-dict', 'address' => 'dadata' ],
			$config['location']['owners']['RU']
		);
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
	// defaultLocality — issue #536 (spec §4.6/D11 amendment, operator decision
	// 25.08.2026): a FIXED default locality is shown to the customer exactly as if
	// they had picked it. `null` unless the policy is `fixed` AND the customer's
	// current record is genuinely an implicit default — never for `geoip` (a guess,
	// stays invisible) or for an EXPLICIT customer record (nothing to seed: the
	// customer's own browser already holds their own text).
	// -------------------------------------------------------------------------

	public function test_default_locality_is_null_when_the_customer_has_no_record(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			null,
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			null,
			null,
			false,
			[],
			Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertNull( $config['location']['defaultLocality'] );
	}

	public function test_default_locality_is_null_when_the_policy_is_off_even_with_an_implicit_customer_record(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( true ),
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			null,
			null,
			false,
			[],
			Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertNull( $config['location']['defaultLocality'] );
	}

	/**
	 * The operator's own decision, verbatim: `geoip` is a guess and stays invisible —
	 * only `fixed` (a merchant-confirmed locality) is ever shown to the customer.
	 */
	public function test_default_locality_is_null_when_the_policy_is_geoip_even_with_an_implicit_customer_record(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( true ),
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			null,
			null,
			false,
			[],
			Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertNull( $config['location']['defaultLocality'] );
	}

	public function test_default_locality_is_null_when_the_customer_record_is_explicit_even_when_the_policy_is_fixed(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( false ),
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			null,
			null,
			false,
			[],
			Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertNull( $config['location']['defaultLocality'] );
	}

	public function test_default_locality_is_populated_with_the_full_record_when_the_policy_is_fixed_and_the_customer_record_is_implicit(): void {
		$customer = $this->customer_record( true );
		$service  = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$customer,
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			null,
			null,
			false,
			[],
			Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame(
			[
				'policy' => 'fixed',
				'record' => $customer['record']->to_array(),
			],
			$config['location']['defaultLocality']
		);
		// The client cannot write a locality's text from a bare key
		// (`location-cascade.js::prefill()`'s own docblock) — this is the ONE field in
		// this config that must carry full components, not just `{ key, level }`.
		$this->assertSame( 'dadata:fias-1', $config['location']['defaultLocality']['record']['key'] );
		$this->assertSame( 'settlement', $config['location']['defaultLocality']['record']['level'] );
	}

	// -------------------------------------------------------------------------
	// chain — issue #330: every level in the customer's saved chain, keyed by
	// level, in the same { key, level } shape as `current`.
	// -------------------------------------------------------------------------

	public function test_chain_is_empty_array_when_the_customer_has_no_record(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true, 'settlement' => true, 'address' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( [], $config['location']['chain'] );
		// A PHP [] must serialize to a JSON array, never an object — the client's
		// own `'object' !== typeof chain` guard treats that as "nothing to seed".
		$this->assertSame( '[]', json_encode( $config['location']['chain'] ) );
	}

	public function test_chain_contains_one_entry_derived_from_current_when_only_current_is_known(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			$this->customer_record( false ),
			[ 'RU' ]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame(
			[ 'settlement' => [ 'key' => 'dadata:fias-1', 'level' => 'settlement' ] ],
			$config['location']['chain']
		);
	}

	/**
	 * The multi-level case (location-chain design §8): a customer whose chain
	 * has a settlement AND an address must see BOTH levels in `chain`, keyed
	 * by level — even though `current` (above) only ever reports the ONE
	 * current record.
	 */
	public function test_chain_contains_every_level_for_a_multi_level_customer_chain(): void {
		$settlement = Location_Record::from_array(
			[
				'key'         => 'dadata:settlement-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
			]
		);
		$address = Location_Record::from_array(
			[
				'key'         => 'dadata:address-1',
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_ADDRESS,
				'country'     => 'RU',
			]
		);

		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true, 'settlement' => true, 'address' => true ],
			[ 'record' => $address, 'implicit' => false, 'saved_at' => 0 ], // "current" is the ADDRESS.
			[ 'RU' ],
			Location_Provider_Registry::MODE_TYPEAHEAD,
			[],
			'RU',
			[
				Location_Record::LEVEL_SETTLEMENT => $settlement,
				Location_Record::LEVEL_ADDRESS     => $address,
			]
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame(
			[
				'settlement' => [ 'key' => 'dadata:settlement-1', 'level' => 'settlement' ],
				'address'    => [ 'key' => 'dadata:address-1', 'level' => 'address' ],
			],
			$config['location']['chain']
		);
		// `current` stays byte-for-byte the ONE current record — unaffected by chain.
		$this->assertSame( [ 'key' => 'dadata:address-1', 'level' => 'address' ], $config['location']['current'] );
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
		// itself via /select) may legitimately carry a provider prefix — and, since
		// issue #352, `owners`, spec D15's one deliberate exception (see
		// `class-checkout-config.php::build_location_block()`'s own `owners` docblock),
		// so THAT key is stripped before this check runs.
		$without_owners = $config;
		unset( $without_owners['location']['owners'] );
		$serialized = (string) json_encode( $without_owners );
		$this->assertStringNotContainsString( 'fake-provider-should-never-leak', $serialized );

		// The other half: `owners` DOES legitimately name it, for every level this fake
		// reports as served.
		$this->assertSame(
			[ 'region' => 'fake-provider-should-never-leak', 'settlement' => 'fake-provider-should-never-leak', 'address' => 'fake-provider-should-never-leak' ],
			$config['location']['owners']['RU']
		);
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
		// resolve_default_country() reads wc_get_base_location() (PR #320 review, finding 3) —
		// this test asserts on the SERIALIZED config never leaking a secret, never on
		// `defaultCountry`, so any well-formed stub is fine here.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		$this->assertInstanceOf( Dadata_Provider::class, $registry->get_providers()[ Location_Provider_Registry::DEFAULT_PROVIDER_ID ] );

		// Issue #530: see the identical comment in
		// test_location_block_default_country_reads_the_real_wc_option_through_the_real_service()
		// for why this mock is needed now that build_location_block() always calls
		// get_popular_settlements_for_country().
		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'all_for_provider' )->andReturn( [] );
		$reflection = new \ReflectionClass( Location_Provider_Registry::class );
		$property   = $reflection->getProperty( 'popular_settlement_store' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $registry, $store );

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active(), 'the bundled DaData provider must be active+configured for this test to be meaningful' );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )->build( Checkout_Fields::from_array( [] ) );

		$serialized = (string) json_encode( $config );
		$this->assertStringNotContainsString( $token, $serialized );
		$this->assertStringNotContainsString( $secret, $serialized );

		// D15: `levels`/`countries`/`mode`/etc. still learn only WHICH LEVELS are served,
		// never WHICH provider — `owners` is the one deliberate exception (issue #352; see
		// `class-checkout-config.php::build_location_block()`'s own `owners` docblock), so
		// it is stripped before this specific check runs.
		$without_owners = $config;
		unset( $without_owners['location']['owners'] );
		$this->assertStringNotContainsString( 'dadata', (string) json_encode( $without_owners ) );

		// The other half: with only ONE provider active (the bundled DaData fallback,
		// nothing else configured), `owners` legitimately names it for every level RU serves.
		$this->assertSame( 'dadata', $config['location']['owners']['RU']['region'] );
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

	/**
	 * Issue #361: `location-cascade.js`'s `emptyTextFor()` swaps this string in over
	 * `noResults`/`noResultsAddress` whenever the most recent `/suggest`/`/list` response's
	 * `within_status` is anything other than `applied`/`not_requested` — the two must never
	 * read the same sentence, same guard shape as `unavailable` vs `noResults` above.
	 */
	public function test_location_block_carries_the_scope_widened_message_distinct_from_no_results(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertIsString( $config['location']['i18n']['scopeWidened'] );
		$this->assertNotSame( '', $config['location']['i18n']['scopeWidened'] );
		$this->assertNotSame( $config['location']['i18n']['noResults'], $config['location']['i18n']['scopeWidened'] );
		$this->assertNotSame( $config['location']['i18n']['noResultsAddress'], $config['location']['i18n']['scopeWidened'] );
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

	/* ------------------------------------------------------------------ *
	 * #627 — `woodev_location_i18n` is NOT an `(array)` cast.
	 *
	 * The mirror filter `woodev_pickup_map_i18n` was fixed in s102; this one
	 * could not be fixed with it, because its defaults were written inline
	 * into the `apply_filters()` call, leaving nothing to fall back TO.
	 * ------------------------------------------------------------------ */

	/**
	 * A scalar return used to become `[ 0 => 'boom' ]` — a map with none of the keys
	 * anyone reads, so every label in the locality typeahead rendered blank and nothing
	 * was logged. The assertion is that the framework's OWN strings survive, not merely
	 * that nothing fatals.
	 *
	 * @return void
	 */
	public function test_location_i18n_falls_back_to_the_defaults_when_the_filter_returns_a_non_array(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_i18n' === $hook ? 'not an array' : $value;
			}
		);

		$strings = Checkout_Config::location_i18n_strings();

		$this->assertArrayNotHasKey( 0, $strings, 'A scalar must be discarded, never cast to a list.' );

		foreach ( [ 'noResults', 'noResultsAddress', 'notPersisted', 'unavailable', 'scopeWidened', 'placeholder', 'searchPlaceholder', 'invalidSettlement' ] as $key ) {
			$this->assertArrayHasKey( $key, $strings, $key . ' must survive a hostile filter return.' );
			$this->assertNotSame( '', $strings[ $key ] );
		}
	}

	/**
	 * The control: a real array IS honoured, and every value is coerced to a string.
	 *
	 * @return void
	 */
	public function test_location_i18n_is_honoured_and_coerces_values_to_strings(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_i18n' === $hook
					? [
						'noResults' => 'Custom empty',
						'unavailable' => 123,
					]
					: $value;
			}
		);

		$strings = Checkout_Config::location_i18n_strings();

		$this->assertSame( 'Custom empty', $strings['noResults'] );
		$this->assertSame( '123', $strings['unavailable'] );
	}

	/**
	 * A PARTIAL array is taken whole — the framework does NOT merge the missing keys
	 * back in, and the mirror filter behaves the same way. That is deliberate and is
	 * now written in the filter's docblock: a dropped key renders BLANK, which is loud,
	 * where a silent substitution would hide a PHP/JS key mismatch.
	 *
	 * Without this test the fallback above could be implemented as a merge and still
	 * pass, which would be a different contract than the one documented.
	 *
	 * @return void
	 */
	public function test_location_i18n_does_not_merge_a_partial_array_with_the_defaults(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_i18n' === $hook ? [ 'noResults' => 'Only this one' ] : $value;
			}
		);

		$strings = Checkout_Config::location_i18n_strings();

		$this->assertSame( [ 'noResults' => 'Only this one' ], $strings );
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

	/**
	 * Issue #405: the checkout typeahead's DISTINCT "the source could not answer" string
	 * — never the same sentence as `noResults` above (see `location-typeahead.js`'s own
	 * `errorText` docblock and `Location_Provider::suggest()`'s "EMPTY VS. FAILED"
	 * section for the full contract this string closes).
	 */
	public function test_location_block_carries_the_unavailable_message_distinct_from_no_results(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertIsString( $config['location']['i18n']['unavailable'] );
		$this->assertNotSame( '', $config['location']['i18n']['unavailable'] );
		$this->assertNotSame( $config['location']['i18n']['noResults'], $config['location']['i18n']['unavailable'] );
	}

	/**
	 * Issue #460: `location-select-modes.js`'s `buildSelectField()` falls back to
	 * `location.i18n.placeholder` when a WooCommerce-rebuilt state field carries neither a
	 * value nor a `placeholder`/`data-placeholder` attribute of its own — see that function's
	 * own docblock for why the rebuilt node never carries one.
	 */
	public function test_location_block_carries_a_placeholder_message(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertIsString( $config['location']['i18n']['placeholder'] );
		$this->assertNotSame( '', $config['location']['i18n']['placeholder'] );
	}

	/**
	 * Issue #540: the placeholder for select2's own SEARCH BOX — a different surface from
	 * `placeholder` above, which names the CLOSED control. With #530's popular list showing
	 * ready-made towns, a customer can read that list as the whole offer and never realise the
	 * box accepts typing (operator, on the rig).
	 *
	 * Asserted DISTINCT from `placeholder` deliberately: the two are one keystroke apart in the
	 * config and reusing «Выберите…» for a search box would be silently wrong rather than
	 * visibly broken. Same shape of guard as `unavailable` vs `noResults` above.
	 */
	public function test_location_block_carries_a_search_placeholder_distinct_from_the_control_placeholder(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertIsString( $config['location']['i18n']['searchPlaceholder'] );
		$this->assertNotSame( '', $config['location']['i18n']['searchPlaceholder'] );
		$this->assertNotSame(
			$config['location']['i18n']['placeholder'],
			$config['location']['i18n']['searchPlaceholder']
		);
	}

	/**
	 * Issue #540: the string travels the SAME public filter every other customer-facing string
	 * in this block does — a shop that wants different wording overrides it there, and never by
	 * patching JS.
	 */
	public function test_search_placeholder_is_filterable_through_woodev_location_i18n(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_i18n' === $hook ? [ 'searchPlaceholder' => 'Введите город' ] : $value;
			}
		);

		$service = new Checkout_Config_Fake_Location_Service( true, [ 'settlement' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'Введите город', $config['location']['i18n']['searchPlaceholder'] );
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
	// Issue #380 — `mode` publishes TWO independent axes read from the real
	// store settings (via Location_Service), no longer one shared hardcoded
	// 'typeahead' constant.
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

		$this->assertSame( Location_Provider_Registry::MODE_RELATED_LIST, $config['location']['mode']['region'] );
		$this->assertSame( Location_Provider_Registry::MODE_RELATED_LIST, $config['location']['mode']['settlement'] );
	}

	/**
	 * Issue #380's whole point: the two axes are genuinely INDEPENDENT — a
	 * combination the legacy single `field_mode` could never express.
	 */
	public function test_mode_region_and_settlement_are_independent(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true ],
			null,
			[ 'RU' ],
			Location_Provider_Registry::MODE_RELATED_LIST,
			[],
			'RU',
			null,
			Location_Provider_Registry::MODE_TYPEAHEAD
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( Location_Provider_Registry::MODE_RELATED_LIST, $config['location']['mode']['region'] );
		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $config['location']['mode']['settlement'] );
	}

	public function test_mode_defaults_to_typeahead(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $config['location']['mode']['region'] );
		$this->assertSame( Location_Provider_Registry::MODE_TYPEAHEAD, $config['location']['mode']['settlement'] );
	}

	// -------------------------------------------------------------------------
	// Issue #528 — `allowCustomSettlement` publishes the merchant's opt-in for
	// letting `ajax-select2` submit a settlement the provider does not carry.
	// -------------------------------------------------------------------------

	public function test_allow_custom_settlement_defaults_to_false(): void {
		$service = new Checkout_Config_Fake_Location_Service( true, [ 'region' => true ], null, [ 'RU' ] );
		$config  = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertFalse( $config['location']['allowCustomSettlement'] );
	}

	public function test_allow_custom_settlement_reads_from_the_location_service_when_enabled(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true,
			[ 'region' => true ],
			null,
			[ 'RU' ],
			Location_Provider_Registry::MODE_AJAX_SELECT2,
			[],
			'RU',
			null,
			Location_Provider_Registry::MODE_AJAX_SELECT2,
			true
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertTrue( $config['location']['allowCustomSettlement'] );
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

		// …and the OWNER must survive that (issue #484). `owners` and `levels` answer two
		// different questions, and in related-list mode they legitimately diverge: this
		// layer owns the level AND renders it as a <select>. Blanking the owner here
		// disarmed `location-cascade.js`'s cross-provider guard in `backwardsFill()`,
		// which is falsy-guarded (`if ( owner && owner !== record.provider_id )`), so a
		// DaData address record silently overwrote the CDEK region field on the rig.
		$this->assertSame(
			'fake-provider-should-never-leak',
			$config['location']['owners']['RU']['region'],
			'our own related-list injection must not make us disown the region level'
		);
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

	// -------------------------------------------------------------------------
	// defaultCountry — issue #296: steps 2+3 of the checkout-field -> WC-store-
	// setting -> RU chain, exposed to the client next to countries/levels.
	// -------------------------------------------------------------------------

	public function test_location_block_carries_the_default_country(): void {
		$service = new Checkout_Config_Fake_Location_Service(
			true, [ 'settlement' => true ], null, [ 'RU' ], Location_Provider_Registry::MODE_TYPEAHEAD, [], 'KZ'
		);
		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'KZ', $config['location']['defaultCountry'] );
	}

	/**
	 * End-to-end through the REAL {@see Location_Service} (not the fixture
	 * fake above) — the "real body" pairing this task's own seam rule
	 * requires: {@see Location_Service::resolve_default_country()} genuinely
	 * reads `wc_get_base_location()` (PR #320 review, finding 3 — never a raw
	 * `get_option( 'woocommerce_default_country' )` read) and splits the
	 * `COUNTRY:STATE` shape, mirroring the exact option format a merchant who
	 * picked a country without naming a state leaves behind.
	 */
	public function test_location_block_default_country_reads_the_real_wc_option_through_the_real_service(): void {
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
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'KZ', 'state' => 'north' ] );

		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		// Issue #530: build_location_block() now calls get_popular_settlements_for_country()
		// for every country, which (the bundled DaData provider declares
		// CAPABILITY_RESOLVE_KEY) reaches Popular_Settlement_Store::all_for_provider() —
		// a real \wpdb touch this pure-unit test has no global for. Inject a mock so
		// this test stays about defaultCountry, not about popular settlements.
		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'all_for_provider' )->andReturn( [] );
		$reflection = new \ReflectionClass( Location_Provider_Registry::class );
		$property   = $reflection->getProperty( 'popular_settlement_store' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $registry, $store );

		$service = new Location_Service( $registry );
		$this->assertTrue( $service->is_active(), 'the bundled DaData provider must be active+configured for this test to be meaningful' );

		$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'RU' ], $service ) )
			->build( Checkout_Fields::from_array( [] ) );

		$this->assertSame( 'KZ', $config['location']['defaultCountry'] );
	}
}
