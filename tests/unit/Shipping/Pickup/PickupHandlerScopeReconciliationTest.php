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
 * Also covers the gate {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::reconcile_pickup_scope()}
 * runs behind (issue #749): the check is per-method AND per-scope, so the gate is
 * keyed by method id rather than a single process-wide bool, and the flag is
 * consumed only once the scope actually claims the method — never on the call
 * that finds nothing to reconcile.
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
	 * Builds a probe handler whose `WC()->session` chosen-shipping-method read is
	 * forced to `$chosen_method_id . ':7'` (or left absent, for `''`) and whose
	 * scope's `type_for_method()` is `$type_for_method`.
	 *
	 * @param string        $plugin_id       distinguishes handlers sharing this
	 *                                       process's static gate.
	 * @param string        $chosen_method_id bare method id `WC()->session` reports
	 *                                        as chosen; `''` leaves no session value
	 *                                        at all, matching how production code
	 *                                        reaches `$method === ''`.
	 * @param callable      $type_for_method fn( string $method_id ): ?string.
	 */
	private function handler_for(
		string $plugin_id,
		string $chosen_method_id,
		callable $type_for_method
	): Pickup_Handler_With_Selection_Probe {
		$scope = new Pickup_Handler_Selection_Test_Scope(
			'key',
			static fn( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ) => 'msk',
			static fn() => 'msk',
			$type_for_method
		);

		return new Pickup_Handler_With_Selection_Probe(
			$plugin_id,
			'pickup_point',
			$this->source(),
			$this->provider(),
			$this->location(),
			$scope,
			new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() ),
			'' === $chosen_method_id ? null : [ $chosen_method_id . ':7' ]
		);
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
	 * Gated to at most once per METHOD ID (issue #749), not once per REQUEST: a
	 * repeat call for the very same method id must still report only once — both
	 * `restore_selection()` and `resolve_chosen_address()` reach
	 * `current_selection_pair()` on a single real checkout render.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_reports_the_same_method_id_only_once(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$handler = $this->handler_for(
			'p',
			'woodev_test_shipping',
			static fn( string $method_id ) => 'woodev_test_shipping' === $method_id ? 'pvz' : null
		);

		Functions\expect( '_doing_it_wrong' )->once();

		$handler->restore_selection( '', 'pickup_point' );
		$handler->restore_selection( '', 'pickup_point' );
	}

	/**
	 * The class-defect regression test (issue #749): the check is per-method AND
	 * per-scope by nature (a single `Selection_Scope` only ever speaks for its own
	 * plugin's methods), so two DIFFERENT method ids in one request must BOTH be
	 * reconciled — a process-wide once-per-request bool would let only the first
	 * one checked ever fire, exactly the shape #736 fixed for `Checkout_Handler`.
	 * With two carriers (the ordinary production arrangement) this is the second
	 * carrier's method never being checked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_reconciles_each_method_id_independently(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$handler_a = $this->handler_for(
			'plugin-a',
			'method_alpha',
			static fn( string $method_id ) => 'method_alpha' === $method_id ? 'pvz' : null
		);
		$handler_b = $this->handler_for(
			'plugin-b',
			'method_beta',
			static fn( string $method_id ) => 'method_beta' === $method_id ? 'postomat' : null
		);

		Functions\expect( '_doing_it_wrong' )->twice();

		$handler_a->restore_selection( '', 'pickup_point' );
		$handler_b->restore_selection( '', 'pickup_point' );
	}

	/**
	 * The gate's other half of issue #749: the flag must be consumed only once the
	 * scope actually claims the method (`$scope_says_pickup === true`), never on a
	 * call that finds nothing to reconcile. A first call where the scope answers
	 * `null` for `method_gamma` must NOT spend that method id's one reconciliation —
	 * a later call for the SAME method id that DOES claim it (and disagrees with the
	 * truth) must still fire.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_is_not_consumed_by_a_call_the_scope_has_no_opinion_on(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$silent_handler = $this->handler_for(
			'plugin-c',
			'method_gamma',
			static fn( string $method_id ) => null
		);
		$claiming_handler = $this->handler_for(
			'plugin-c',
			'method_gamma',
			static fn( string $method_id ) => 'method_gamma' === $method_id ? 'pvz' : null
		);

		Functions\expect( '_doing_it_wrong' )->once();

		$silent_handler->restore_selection( '', 'pickup_point' );
		$claiming_handler->restore_selection( '', 'pickup_point' );
	}

	/**
	 * `''` is the {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope}
	 * interface's own definition of "unknown", never a real method choice — skipped
	 * unconditionally, even when the scope would otherwise claim it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciliation_skips_the_empty_method_id(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );
		Functions\when( 'wc_clean' )->returnArg();

		$handler = $this->handler_for(
			'plugin-d',
			'',
			static fn( string $method_id ) => '' === $method_id ? 'pvz' : null
		);

		Functions\expect( '_doing_it_wrong' )->never();

		$handler->restore_selection( '', 'pickup_point' );
	}
}
