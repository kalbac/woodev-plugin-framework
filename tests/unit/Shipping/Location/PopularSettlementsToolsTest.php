<?php
/**
 * Unit tests for Popular_Settlements_Tools — the D8 merchant actions bridged
 * into Shipping_Tools_Registry (issue #505, D3/D6): the capability gate (both
 * at the selector-build view AND the run-time re-check), the active-provider
 * default, and the sweep/clear result messages.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Locality_Key;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Framework\Shipping\Location\Popular_Settlements_Tools;
use Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-composite-settings-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-phone-mask-patterns.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-tool-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tools-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verification.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verifier.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlements-tools.php';

/**
 * Declares CAPABILITY_RESOLVE_KEY by overriding resolve_key() — capability is
 * reflection-derived by Abstract_Location_Provider.
 */
final class Popular_Settlements_Tools_Resolving_Fixture_Provider extends Abstract_Location_Provider {

	private string $id;
	private string $name;

	public function __construct( string $id, string $name ) {
		$this->id   = $id;
		$this->name = $name;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function resolve_key( string $key ): ?Location_Record {
		return null;
	}
}

/**
 * Declares CAPABILITY_RESOLVE_KEY (reflection-derived) but resolve_key()
 * always throws — simulates a transport failure so a swept entry lands in
 * Popular_Settlement_Verification::OUTCOME_FAILED (M2/T1).
 */
final class Popular_Settlements_Tools_Failing_Fixture_Provider extends Abstract_Location_Provider {

	public function get_id(): string {
		return 'dadata';
	}

	public function get_name(): string {
		return 'DaData';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function resolve_key( string $key ): ?Location_Record {
		throw new \RuntimeException( 'simulated transport failure' );
	}
}

/**
 * Does NOT override resolve_key() — D3-ineligible.
 */
final class Popular_Settlements_Tools_Non_Resolving_Fixture_Provider extends Abstract_Location_Provider {

	public function get_id(): string {
		return 'non-resolving';
	}

	public function get_name(): string {
		return 'Non Resolving';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_SETTLEMENT ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}
}

/**
 * Store spy: no rows, no \wpdb — enough to exercise sweep()'s all-zero-counts
 * path and clear_provider()'s row-count reporting without a real database.
 */
final class Popular_Settlements_Tools_Store_Spy extends Popular_Settlement_Store {

	public ?string $cleared_provider_id = null;
	public int $clear_return = 0;

	/** @var Popular_Settlement_Entry[] rows returned by all_for_provider(), keyed however the test needs. */
	public array $rows = [];

	public function all_for_provider( string $provider_id ): array {
		return $this->rows;
	}

	public function clear_provider( string $provider_id ): int {
		$this->cleared_provider_id = $provider_id;

		return $this->clear_return;
	}
}

final class PopularSettlementsToolsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Location_Provider_Registry::instance()->reset_for_tests();
		Shipping_Tools_Registry::reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Shipping_Tools_Registry::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Installs a fully-formed Location_Provider_Registry singleton (bypassing
	 * the private constructor and `collect()`'s WP-heavy path — same reflection
	 * technique LocationProviderRegistryPopularSettlementsTest already uses)
	 * with the given providers and active provider id.
	 *
	 * @param array<string,\Woodev\Framework\Shipping\Location\Location_Provider> $providers id => instance.
	 * @param string|null                                                        $active_id resolved active provider id, or null for "no settings handler yet".
	 * @param Popular_Settlement_Store|null                                      $store     injected store spy.
	 */
	private function install_registry( array $providers, ?string $active_id, ?Popular_Settlement_Store $store = null ): void {
		$reflection = new \ReflectionClass( Location_Provider_Registry::class );
		$registry   = $reflection->newInstanceWithoutConstructor();

		$providers_property = $reflection->getProperty( 'providers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$providers_property->setAccessible( true );
		}
		$providers_property->setValue( $registry, $providers );

		$needed_property = $reflection->getProperty( 'needed' );
		if ( PHP_VERSION_ID < 80100 ) {
			$needed_property->setAccessible( true );
		}
		$needed_property->setValue( $registry, true );

		if ( null !== $active_id ) {
			// $settings_handler is typed ?Location_Settings — a bare Mockery double
			// does not satisfy that type; mock the real class instead.
			$handler = Mockery::mock( \Woodev\Framework\Shipping\Location\Location_Settings::class );
			$handler->shouldReceive( 'get_value' )->with( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER )->andReturn( $active_id );

			$handler_property = $reflection->getProperty( 'settings_handler' );
			if ( PHP_VERSION_ID < 80100 ) {
				$handler_property->setAccessible( true );
			}
			$handler_property->setValue( $registry, $handler );
		}

		if ( null !== $store ) {
			$store_property = $reflection->getProperty( 'popular_settlement_store' );
			if ( PHP_VERSION_ID < 80100 ) {
				$store_property->setAccessible( true );
			}
			$store_property->setValue( $registry, $store );
		}

		$instance_property = $reflection->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance_property->setAccessible( true );
		}
		$instance_property->setValue( null, $registry );
	}

	private function stub_active_provider_filters(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);
	}

	public function test_no_tools_when_no_provider_is_resolve_key_capable(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Non_Resolving_Fixture_Provider() ],
			'dadata'
		);

		$tools = Popular_Settlements_Tools::register_tools( [] );

		$this->assertSame( [], $tools );
	}

	public function test_two_tools_registered_when_a_provider_is_capable(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ) ],
			'dadata'
		);

		$tools = Popular_Settlements_Tools::register_tools( [] );

		$this->assertCount( 2, $tools );
		$this->assertSame(
			[ 'popular-settlements-sweep', 'popular-settlements-clear' ],
			array_map( static fn( $t ) => $t->get_id(), $tools )
		);
	}

	public function test_selector_lists_only_capable_providers_and_defaults_to_the_active_one(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[
				'dadata' => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ),
				'other'  => new Popular_Settlements_Tools_Non_Resolving_Fixture_Provider(),
			],
			'dadata'
		);

		$tools    = Popular_Settlements_Tools::register_tools( [] );
		$selector = $tools[0]->to_array()['selector'];

		$this->assertSame( [ [ 'value' => 'dadata', 'label' => 'DaData' ] ], $selector['options'] );
		$this->assertSame( 'dadata', $selector['default'] );
		$this->assertSame( 'provider_id', $selector['name'] );
	}

	public function test_selector_defaults_to_first_capable_when_active_provider_is_not_capable(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[
				'incapable' => new Popular_Settlements_Tools_Non_Resolving_Fixture_Provider(),
				'capable'   => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'capable', 'Capable' ),
			],
			'incapable'
		);

		$tools    = Popular_Settlements_Tools::register_tools( [] );
		$selector = $tools[0]->to_array()['selector'];

		$this->assertSame( 'capable', $selector['default'] );
	}

	public function test_run_sweep_reports_counts_in_russian(): void {
		$this->stub_active_provider_filters();
		$store = new Popular_Settlements_Tools_Store_Spy();
		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ) ],
			'dadata',
			$store
		);

		$result = Popular_Settlements_Tools::run_sweep( [ 'provider_id' => 'dadata' ] );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'Проверено: 0', $result->get_message() );
	}

	/**
	 * M2/T1: `failed` is `unverified`, not `gone` (popular-settlements design
	 * D6) — a sweep that could not verify every row it checked must not be
	 * reported as a success, and the two must be distinguishable BY OUTCOME,
	 * not only by the counts embedded in the message text.
	 */
	public function test_run_sweep_reports_failure_when_a_row_could_not_be_verified(): void {
		$this->stub_active_provider_filters();

		$record = Location_Record::from_array(
			[
				'key'         => Locality_Key::compose( 'dadata', 'settlement-1' ),
				'provider_id' => 'dadata',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
			]
		);

		$store       = new Popular_Settlements_Tools_Store_Spy();
		$store->rows = [ new Popular_Settlement_Entry( 1, 'dadata', 'RU', $record, 0, null, null, time() ) ];

		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Failing_Fixture_Provider() ],
			'dadata',
			$store
		);

		$result = Popular_Settlements_Tools::run_sweep( [ 'provider_id' => 'dadata' ] );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Ошибок: 1', $result->get_message() );
	}

	public function test_run_sweep_fails_when_the_requested_provider_is_not_capable(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[
				'dadata'    => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ),
				'incapable' => new Popular_Settlements_Tools_Non_Resolving_Fixture_Provider(),
			],
			'dadata'
		);

		$result = Popular_Settlements_Tools::run_sweep( [ 'provider_id' => 'incapable' ] );

		$this->assertFalse( $result->is_success() );
	}

	public function test_run_sweep_fails_for_an_unknown_provider_id_even_if_absent_from_the_selector(): void {
		$this->stub_active_provider_filters();
		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ) ],
			'dadata'
		);

		// D3: never trust the incoming id beyond a lookup — a crafted/forged id
		// that names nothing registered fails exactly like an incapable one.
		$result = Popular_Settlements_Tools::run_sweep( [ 'provider_id' => 'forged' ] );

		$this->assertFalse( $result->is_success() );
	}

	public function test_run_clear_reports_deleted_count_and_targets_the_requested_provider(): void {
		$this->stub_active_provider_filters();
		$store               = new Popular_Settlements_Tools_Store_Spy();
		$store->clear_return = 7;
		$this->install_registry(
			[ 'dadata' => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ) ],
			'dadata',
			$store
		);

		$result = Popular_Settlements_Tools::run_clear( [ 'provider_id' => 'dadata' ] );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( '7', $result->get_message() );
		$this->assertSame( 'dadata', $store->cleared_provider_id );
	}

	public function test_run_clear_fails_when_the_requested_provider_is_not_capable(): void {
		$this->stub_active_provider_filters();
		$store = new Popular_Settlements_Tools_Store_Spy();
		$this->install_registry(
			[
				'dadata'    => new Popular_Settlements_Tools_Resolving_Fixture_Provider( 'dadata', 'DaData' ),
				'incapable' => new Popular_Settlements_Tools_Non_Resolving_Fixture_Provider(),
			],
			'dadata',
			$store
		);

		$result = Popular_Settlements_Tools::run_clear( [ 'provider_id' => 'incapable' ] );

		$this->assertFalse( $result->is_success() );
		$this->assertNull( $store->cleared_provider_id, 'must never touch the store for an ineligible provider' );
	}
}
