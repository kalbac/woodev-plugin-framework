<?php
/**
 * Tests for Pickup_Handler's reconciliation of Selection_Scope::type_for_method()
 * against the framework's single pickup-method truth (issue #709).
 *
 * `type_for_method()` is the one pickup declaration that cannot be derived from
 * Shipping_Method::is_pickup_shipping() — it stays plugin-owned domain knowledge
 * (see Selection_Scope's own docblock). This reconciliation is the only thing that
 * can catch it drifting from the other three declarations, which since #709 all
 * default to the same derived truth.
 *
 * Split into its own file rather than added to the already-6000-line
 * PickupHandlerTest.php: every test here needs `define( 'WP_DEBUG', true )`, which
 * — like `Functions\when( 'WC' )` — cannot be undone once run, so each test runs
 * `@runInSeparateProcess` (see CheckoutConfigTest::config_with_states()'s own
 * docblock for the 21-test breakage measured the one time that was done inline).
 * Reuses PickupHandlerTest.php's own test doubles (`Pickup_Handler_With_Selection_Probe`,
 * `Pickup_Handler_Selection_Test_Scope`, `Pickup_Handler_Test_Source`,
 * `Pickup_Handler_Test_Map_Provider`) rather than redeclaring them.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler
 */
class PickupHandlerScopeReconciliationTest extends TestCase {

	/**
	 * Pulls in PickupHandlerTest.php's own test doubles. Safe to call from several
	 * test methods (each its own process) — `require_once` is idempotent within a
	 * process, and the file's `class_exists()`-guarded global stubs are safe too.
	 */
	private static function require_doubles(): void {
		require_once __DIR__ . '/PickupHandlerTest.php';
	}

	private function source(): Pickup_Handler_Test_Source {
		return new Pickup_Handler_Test_Source( Point_Source::STRATEGY_BULK, static fn( string $id ) => null );
	}

	private function provider(): Pickup_Handler_Test_Map_Provider {
		return new Pickup_Handler_Test_Map_Provider( 'yandex', [] );
	}

	private function location(): array {
		return [ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ];
	}

	/**
	 * The scope claims `woodev_test_shipping` as a pickup method (`type_for_method()`
	 * returns non-null) while `is_pickup_shipping()` truth — WC() is unavailable in
	 * this test, so the truth is `[]` — disowns it entirely. Must fire once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_fires_when_the_scope_claims_a_method_the_truth_disowns(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$scope = new Pickup_Handler_Selection_Test_Scope(
			'key',
			static fn( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ) => 'msk',
			static fn() => 'msk',
			static fn( string $method_id ) => 'woodev_test_shipping' === $method_id ? 'pvz' : null
		);

		$handler = new Pickup_Handler_With_Selection_Probe(
			'p',
			'pickup_point',
			$this->source(),
			$this->provider(),
			$this->location(),
			$scope,
			new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() ),
			[ 'woodev_test_shipping:7' ]
		);

		Functions\expect( '_doing_it_wrong' )->once();

		$handler->restore_selection( '', 'pickup_point' );
	}

	/**
	 * A scope answering `null` for the chosen method makes no claim at all — nothing
	 * to reconcile, regardless of what the truth says. This is also the ONLY
	 * direction this reconciliation checks (see
	 * `Pickup_Handler::reconcile_pickup_scope()`'s own docblock for why the other
	 * direction would false-positive on a foreign plugin's pickup method).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_stays_silent_when_the_scope_answers_null(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$scope = new Pickup_Handler_Selection_Test_Scope(
			'key',
			static fn( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ) => 'msk',
			static fn() => 'msk',
			static fn( string $method_id ) => null
		);

		$handler = new Pickup_Handler_With_Selection_Probe(
			'p',
			'pickup_point',
			$this->source(),
			$this->provider(),
			$this->location(),
			$scope,
			new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() ),
			[ 'woodev_test_shipping:7' ]
		);

		Functions\expect( '_doing_it_wrong' )->never();

		$handler->restore_selection( '', 'pickup_point' );
	}

	/**
	 * Gated to at most once per REQUEST, not once per {@see Pickup_Handler} call —
	 * both `restore_selection()` and `resolve_chosen_address()` reach
	 * `current_selection_pair()` on a single real checkout render.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_runs_at_most_once_per_request(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$scope = new Pickup_Handler_Selection_Test_Scope(
			'key',
			static fn( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ) => 'msk',
			static fn() => 'msk',
			static fn( string $method_id ) => 'woodev_test_shipping' === $method_id ? 'pvz' : null
		);

		$handler = new Pickup_Handler_With_Selection_Probe(
			'p',
			'pickup_point',
			$this->source(),
			$this->provider(),
			$this->location(),
			$scope,
			new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() ),
			[ 'woodev_test_shipping:7' ]
		);

		Functions\expect( '_doing_it_wrong' )->once();

		$handler->restore_selection( '', 'pickup_point' );
		$handler->restore_selection( '', 'pickup_point' );
	}
}
