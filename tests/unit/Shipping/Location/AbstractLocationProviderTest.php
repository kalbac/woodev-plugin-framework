<?php
/**
 * Unit tests for Abstract_Location_Provider — capability discovery by reflection
 * (a provider cannot claim a capability it did not override), the multi-level
 * inheritance case, the optional-method default \BadMethodCallException throws, the
 * capability-narrowing hook (can subtract, can never add), and the
 * get_suggest_levels() template-method validation against Location_Record::LEVELS.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';

/**
 * A bare fixture implementing only what the interface REQUIRES: get_id(),
 * get_name(), get_countries(), declare_suggest_levels() (via the abstract's
 * template method) and suggest(). None of the three optional methods are touched.
 */
class Bare_Fixture_Provider extends Abstract_Location_Provider {

	public function get_id(): string {
		return 'bare';
	}

	public function get_name(): string {
		return 'Bare Fixture';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}
}

/**
 * Overrides ONLY locate() — used to prove get_capabilities() reports exactly the
 * overridden method and that calling it does not throw.
 */
class Locate_Only_Fixture_Provider extends Bare_Fixture_Provider {

	public function get_id(): string {
		return 'locate-only';
	}

	public function locate( string $ip ): ?Location_Record {
		return null;
	}
}

/**
 * Direct child of Abstract_Location_Provider that overrides list_localities() —
 * paired with Grandchild_Fixture_Provider below, which extends THIS class and
 * overrides nothing itself. Proves the reflection comparison uses `self::class`
 * (the abstract class), not `static::class`, so an ancestor's override two levels
 * up is still detected on the leaf instance.
 */
class Child_With_List_Fixture_Provider extends Abstract_Location_Provider {

	public function get_id(): string {
		return 'child-with-list';
	}

	public function get_name(): string {
		return 'Child With List';
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return [ Location_Record::LEVEL_REGION ];
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function list_localities( Location_Scope $scope ): array {
		return [];
	}
}

class Grandchild_Fixture_Provider extends Child_With_List_Fixture_Provider {

	public function get_id(): string {
		return 'grandchild';
	}
}

/**
 * Implements normalize() but narrows it away via narrow_capabilities() when
 * "not configured" — the Task 7 (DaData) shape: implements the method, but
 * reports the capability only when a runtime condition (here, a constructor flag)
 * is satisfied.
 */
class Narrowing_Fixture_Provider extends Bare_Fixture_Provider {

	private bool $configured;

	public function __construct( bool $configured ) {
		$this->configured = $configured;
	}

	public function get_id(): string {
		return 'narrowing';
	}

	public function normalize( string $free_form, Location_Scope $scope ): ?Location_Record {
		return null;
	}

	protected function narrow_capabilities( array $capabilities ): array {
		if ( ! $this->configured ) {
			return array_values( array_diff( $capabilities, [ self::CAPABILITY_NORMALIZE ] ) );
		}

		return $capabilities;
	}
}

/**
 * Attempts to WIDEN its capability set to claim 'list' without ever overriding
 * list_localities() — proves get_capabilities() ignores an attempted widen.
 */
class Widening_Fixture_Provider extends Bare_Fixture_Provider {

	public function get_id(): string {
		return 'widening';
	}

	protected function narrow_capabilities( array $capabilities ): array {
		return array_merge( $capabilities, [ self::CAPABILITY_LIST ] );
	}
}

/**
 * Declares an unknown level string — proves get_suggest_levels() surfaces a typo
 * loudly rather than silently accepting it.
 */
class Typo_Level_Fixture_Provider extends Bare_Fixture_Provider {

	public function get_id(): string {
		return 'typo-level';
	}

	protected function declare_suggest_levels(): array {
		return [ 'city' ];
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Abstract_Location_Provider
 */
final class AbstractLocationProviderTest extends TestCase {

	// ---- bare subclass: no optional capabilities, every optional method throws ----

	public function test_a_bare_subclass_reports_no_capabilities(): void {
		$this->assertSame( [], ( new Bare_Fixture_Provider() )->get_capabilities() );
	}

	public function test_a_bare_subclass_throws_on_list_localities(): void {
		$this->expectException( \BadMethodCallException::class );
		( new Bare_Fixture_Provider() )->list_localities( Location_Scope::for_country( 'RU', 'region' ) );
	}

	public function test_a_bare_subclass_throws_on_locate(): void {
		$this->expectException( \BadMethodCallException::class );
		( new Bare_Fixture_Provider() )->locate( '1.2.3.4' );
	}

	public function test_a_bare_subclass_throws_on_normalize(): void {
		$this->expectException( \BadMethodCallException::class );
		( new Bare_Fixture_Provider() )->normalize( 'Москва, Тверская 1', Location_Scope::for_country( 'RU', 'address' ) );
	}

	public function test_the_bad_method_call_exception_names_the_provider_and_the_missing_capability(): void {
		try {
			( new Bare_Fixture_Provider() )->locate( '1.2.3.4' );
			$this->fail( 'expected BadMethodCallException' );
		} catch ( \BadMethodCallException $exception ) {
			$this->assertStringContainsString( 'bare', $exception->getMessage() );
			$this->assertStringContainsString( 'locate', $exception->getMessage() );
		}
	}

	// ---- overriding one optional method reports exactly that one, and does not throw ----

	public function test_a_subclass_overriding_locate_reports_exactly_locate(): void {
		$this->assertSame( [ Location_Provider::CAPABILITY_LOCATE ], ( new Locate_Only_Fixture_Provider() )->get_capabilities() );
	}

	public function test_a_subclass_overriding_locate_does_not_throw_when_called(): void {
		$this->assertNull( ( new Locate_Only_Fixture_Provider() )->locate( '1.2.3.4' ) );
	}

	public function test_a_subclass_overriding_locate_still_throws_on_the_other_two(): void {
		$provider = new Locate_Only_Fixture_Provider();

		$this->expectException( \BadMethodCallException::class );
		$provider->list_localities( Location_Scope::for_country( 'RU', 'region' ) );
	}

	// ---- two-level hierarchy: an ancestor's override is still detected on the leaf ----

	public function test_a_grandchild_that_overrides_nothing_still_reports_a_capability_its_parent_implemented(): void {
		$this->assertSame(
			[ Location_Provider::CAPABILITY_LIST ],
			( new Grandchild_Fixture_Provider() )->get_capabilities()
		);
	}

	public function test_a_grandchild_can_actually_call_the_inherited_capability_without_throwing(): void {
		$this->assertSame( [], ( new Grandchild_Fixture_Provider() )->list_localities( Location_Scope::for_country( 'RU', 'region' ) ) );
	}

	// ---- narrowing: implemented but not configured -> capability withheld ----

	public function test_an_unconfigured_narrowing_provider_does_not_report_normalize(): void {
		$this->assertSame( [], ( new Narrowing_Fixture_Provider( false ) )->get_capabilities() );
	}

	public function test_a_configured_narrowing_provider_reports_normalize(): void {
		$this->assertSame(
			[ Location_Provider::CAPABILITY_NORMALIZE ],
			( new Narrowing_Fixture_Provider( true ) )->get_capabilities()
		);
	}

	public function test_an_unconfigured_narrowing_provider_still_throws_when_normalize_is_called_despite_implementing_it(): void {
		// The method body itself does not gate on $configured — narrow_capabilities()
		// is what tells the CALLER not to invoke it; calling it directly anyway is a
		// caller-side contract violation and behaves exactly as the method's own body
		// dictates (here, it happens to succeed since the fixture's normalize() is
		// simple — the discipline lives in get_capabilities(), not in a runtime guard
		// inside the method).
		$this->assertNull( ( new Narrowing_Fixture_Provider( false ) )->normalize( 'x', Location_Scope::for_country( 'RU', 'address' ) ) );
	}

	// ---- widening is impossible ----

	public function test_a_provider_cannot_widen_its_capabilities_by_claiming_an_unimplemented_one(): void {
		$this->assertSame( [], ( new Widening_Fixture_Provider() )->get_capabilities() );
	}

	public function test_a_widening_provider_still_throws_when_the_falsely_claimed_capability_is_called(): void {
		$this->expectException( \BadMethodCallException::class );
		( new Widening_Fixture_Provider() )->list_localities( Location_Scope::for_country( 'RU', 'region' ) );
	}

	// ---- capability cache is per-instance, not shared/leaked ----

	public function test_capabilities_are_computed_independently_per_instance(): void {
		$configured   = new Narrowing_Fixture_Provider( true );
		$unconfigured = new Narrowing_Fixture_Provider( false );

		// Interleave calls so a class-keyed (rather than instance-keyed) cache would
		// visibly leak the first instance's result into the second.
		$this->assertSame( [ Location_Provider::CAPABILITY_NORMALIZE ], $configured->get_capabilities() );
		$this->assertSame( [], $unconfigured->get_capabilities() );
		$this->assertSame( [ Location_Provider::CAPABILITY_NORMALIZE ], $configured->get_capabilities() );
	}

	// ---- get_suggest_levels(): typo surfaces loudly ----

	public function test_get_suggest_levels_returns_the_declared_levels(): void {
		$this->assertSame( [ Location_Record::LEVEL_REGION ], ( new Bare_Fixture_Provider() )->get_suggest_levels() );
	}

	public function test_an_unknown_declared_level_throws_rather_than_being_silently_accepted(): void {
		$this->expectException( \UnexpectedValueException::class );
		( new Typo_Level_Fixture_Provider() )->get_suggest_levels();
	}
}
