<?php
/**
 * Unit tests for Location_Settings::test_connection() — #488 D8, the two
 * popular-settlements merchant actions («Проверить актуальность популярных
 * городов» / «Очистить список популярных городов»). Covers the D4 capability
 * re-check (no active provider / active provider without CAPABILITY_RESOLVE_KEY
 * both fail cleanly), the unknown-connection-id guard, and that each action
 * calls through to the right store/verifier operation and reports its result.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Settings;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-connection-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/interface-connection-test.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verification.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-verifier.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';

/**
 * A `resolve_key()`-capable fixture provider (D4-eligible) — answers per key
 * from a caller-supplied map; a key absent from the map answers `null`
 * ("gone"), matching {@see \Woodev\Framework\Shipping\Location\Location_Provider::resolve_key()}'s
 * own "asked and told no" contract.
 */
final class Location_Settings_Test_Resolving_Provider extends Abstract_Location_Provider {

	private string $id;

	/** @var array<string, Location_Record> */
	private array $answers;

	/**
	 * @param array<string, Location_Record> $answers key => the record resolve_key() answers with.
	 */
	public function __construct( string $id, array $answers = [] ) {
		$this->id      = $id;
		$this->answers = $answers;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return 'Resolving Fixture';
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
		return $this->answers[ $key ] ?? null;
	}
}

/**
 * Does NOT override `resolve_key()` — D4-ineligible (its reflection-derived
 * capability set never contains `CAPABILITY_RESOLVE_KEY`).
 */
final class Location_Settings_Test_Non_Resolving_Provider extends Abstract_Location_Provider {

	private string $id;

	public function __construct( string $id ) {
		$this->id = $id;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_name(): string {
		return 'Non-Resolving Fixture';
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
 * @covers \Woodev\Framework\Shipping\Location\Location_Settings::test_connection
 */
final class LocationSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		// Location_Settings::register_settings() always resolves the default-locality
		// picker's country via Location_Service::resolve_default_country(), regardless
		// of what this test is actually about.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);
	}

	/**
	 * @param \Woodev\Framework\Shipping\Location\Location_Provider|null $active_provider
	 */
	private function handler( $active_provider, Popular_Settlement_Store $store ): Location_Settings {
		return new Location_Settings(
			'location',
			[],
			[],
			[],
			[],
			[],
			null,
			$store,
			$active_provider
		);
	}

	private function record( string $provider_id, string $native_id ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $provider_id . ':' . $native_id,
				'provider_id' => $provider_id,
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
			]
		);
	}

	public function test_unknown_connection_id_throws(): void {
		$provider = new Location_Settings_Test_Resolving_Provider( 'dadata' );
		$handler  = $this->handler( $provider, Mockery::mock( Popular_Settlement_Store::class ) );

		$this->expectException( \Woodev_Plugin_Exception::class );

		$handler->test_connection( 'not_a_real_connection_id', [] );
	}

	/**
	 * D4/D8: no active provider at all — both actions must fail cleanly rather
	 * than fatal reaching for a null provider's capabilities.
	 */
	public function test_no_active_provider_fails_both_actions_without_touching_the_store(): void {
		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldNotReceive( 'all_for_provider' );
		$store->shouldNotReceive( 'clear_provider' );

		$handler = $this->handler( null, $store );

		$verify = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_VERIFY, [] );
		$this->assertFalse( $verify->is_success() );
		$this->assertNotSame( '', $verify->get_message() );

		$clear = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_CLEAR, [] );
		$this->assertFalse( $clear->is_success() );
		$this->assertNotSame( '', $clear->get_message() );
	}

	/**
	 * D4/D8: an active provider that does not declare CAPABILITY_RESOLVE_KEY —
	 * spec D8's "absent, not present-and-disabled" is enforced primarily at the
	 * section-visibility level (Shipping_Settings_Tab), but this defensive
	 * re-check must also hold for a request that reaches here anyway (a stale
	 * client tab, a hand-crafted request).
	 */
	public function test_active_provider_without_the_capability_fails_both_actions_without_touching_the_store(): void {
		$provider = new Location_Settings_Test_Non_Resolving_Provider( 'dadata' );
		$store    = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldNotReceive( 'all_for_provider' );
		$store->shouldNotReceive( 'clear_provider' );

		$handler = $this->handler( $provider, $store );

		$verify = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_VERIFY, [] );
		$this->assertFalse( $verify->is_success() );

		$clear = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_CLEAR, [] );
		$this->assertFalse( $clear->is_success() );
	}

	/**
	 * «Проверить актуальность популярных городов» sweeps the ACTIVE provider's
	 * own rows (never a different provider's) through the real
	 * Popular_Settlement_Verifier and reports the counts it returns.
	 */
	public function test_verify_sweeps_the_active_providers_rows_and_reports_the_counts(): void {
		$record = $this->record( 'dadata', '1' );
		$entry  = new Popular_Settlement_Entry( 1, 'dadata', 'RU', $record, 5, 1700000000, null, 1600000000 );

		// resolve_key() answers with the SAME record -> "unchanged".
		$provider = new Location_Settings_Test_Resolving_Provider( 'dadata', [ $record->key() => $record ] );

		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'all_for_provider' )->once()->with( 'dadata' )->andReturn( [ $entry ] );
		$store->shouldReceive( 'touch_verified' )->once()->with( 1 );
		$store->shouldNotReceive( 'clear_provider' );

		$handler = $this->handler( $provider, $store );

		$result = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_VERIFY, [] );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'Проверено записей: 1', $result->get_message() );
		$this->assertStringContainsString( 'Без изменений: 1', $result->get_message() );
		$this->assertStringContainsString( 'Обновлено: 0', $result->get_message() );
		$this->assertStringContainsString( 'Удалено: 0', $result->get_message() );
		$this->assertStringContainsString( 'Ошибок: 0', $result->get_message() );
	}

	/**
	 * «Очистить список популярных городов» clears the ACTIVE provider's id,
	 * never a hardcoded or different one, and reports the row count deleted.
	 */
	public function test_clear_deletes_every_row_of_the_active_provider_and_reports_the_count(): void {
		$provider = new Location_Settings_Test_Resolving_Provider( 'dadata' );

		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'clear_provider' )->once()->with( 'dadata' )->andReturn( 7 );
		$store->shouldNotReceive( 'all_for_provider' );

		$handler = $this->handler( $provider, $store );

		$result = $handler->test_connection( Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_CLEAR, [] );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'Удалено записей: 7', $result->get_message() );
	}

	/**
	 * #488 D8 round 2 critic HIGH: the merchant switched the `active_provider`
	 * select to a DIFFERENT provider without saving — the two connection
	 * sections were rendered from the PERSISTED provider and stay visible, but
	 * acting on the persisted provider now would silently touch a list the
	 * merchant is no longer looking at. `$values` carries the STAGED
	 * (unsaved) `active_provider` id; a mismatch against the persisted one
	 * must refuse BOTH actions without touching the store at all — even
	 * though the persisted provider ('dadata') IS capable.
	 */
	public function test_staged_active_provider_mismatch_refuses_both_actions_without_touching_the_store(): void {
		$provider = new Location_Settings_Test_Resolving_Provider( 'dadata' );
		$store    = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldNotReceive( 'all_for_provider' );
		$store->shouldNotReceive( 'clear_provider' );

		$handler = $this->handler( $provider, $store );

		$verify = $handler->test_connection(
			Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_VERIFY,
			[ 'active_provider' => 'some_other_provider' ]
		);
		$this->assertFalse( $verify->is_success() );
		$this->assertStringContainsString( 'сохран', mb_strtolower( $verify->get_message() ) );

		$clear = $handler->test_connection(
			Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_CLEAR,
			[ 'active_provider' => 'some_other_provider' ]
		);
		$this->assertFalse( $clear->is_success() );
		$this->assertStringContainsString( 'сохран', mb_strtolower( $clear->get_message() ) );
	}

	/**
	 * A staged `active_provider` that matches the persisted one (the common
	 * case — the merchant hasn't touched the select, or re-selected the same
	 * value) is NOT a mismatch and must not block the action.
	 */
	public function test_staged_active_provider_matching_the_persisted_one_does_not_block_clear(): void {
		$provider = new Location_Settings_Test_Resolving_Provider( 'dadata' );

		$store = Mockery::mock( Popular_Settlement_Store::class );
		$store->shouldReceive( 'clear_provider' )->once()->with( 'dadata' )->andReturn( 3 );

		$handler = $this->handler( $provider, $store );

		$result = $handler->test_connection(
			Location_Settings::CONNECTION_POPULAR_SETTLEMENTS_CLEAR,
			[ 'active_provider' => 'dadata' ]
		);

		$this->assertTrue( $result->is_success() );
	}
}
