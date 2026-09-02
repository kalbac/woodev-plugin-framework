<?php
/**
 * Tests for Pickup_Handler::reconcile_location_context_wiring() — the loud notice for a
 * `Pickup_Handler` built WITHOUT `$plugin` while the shop's Location Provider layer is
 * active (issue #746).
 *
 * Before this card, that combination degraded silently: `location_context()` returned
 * `null`, the browser config carried no `location` block, and
 * `pickup-mount.js::resolveLocalityKey()` fell back to a DOM-read locality NAME instead of
 * the Location Provider layer's namespaced KEY — with no observable difference on an
 * unambiguous settlement name. This file covers the four cases the card calls out: the
 * notice fires when it should, stays silent when `$plugin` is wired, stays silent when the
 * layer is not active, and — the s113 trap, gotcha
 * `a-process-static-once-per-request-gate-checks-only-the-first-plugin` — a SECOND handler
 * built for a DIFFERENT plugin in the same request is still checked, not silently skipped
 * because a first handler already ran the reconciliation.
 *
 * Split into its own file rather than added to the already-huge PickupHandlerTest.php —
 * same reasoning as PickupHandlerScopeReconciliationTest.php's own docblock: every test
 * here needs `define( 'WP_DEBUG', true )`, which cannot be undone once run, so each test
 * runs `@runInSeparateProcess`. Reuses PickupHandlerTest.php's own test doubles
 * (`Pickup_Handler_Test_Source`, `Pickup_Handler_Test_Map_Provider`,
 * `Pickup_Handler_Location_Fixture_Plugin`) rather than redeclaring them.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Map\Map_Provider;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler
 */
class PickupHandlerLocationWiringTest extends TestCase {

	/**
	 * Pulls in PickupHandlerTest.php's own test doubles. Safe to call from several test
	 * methods (each its own process) — `require_once` is idempotent within a process, and
	 * the file's `class_exists()`-guarded global stubs are safe too.
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
	 * A bare {@see Pickup_Handler_Location_Fixture_Plugin} built via
	 * `newInstanceWithoutConstructor()` — same discipline PickupHandlerTest.php's own
	 * `location_plugin()` helper uses. This file never reads anything off it beyond its
	 * mere non-null-ness (the reconciliation short-circuits before touching
	 * `get_location_service()` at all once `$plugin` is non-null), so no further wiring is
	 * needed.
	 */
	private function plugin(): Pickup_Handler_Location_Fixture_Plugin {
		return ( new \ReflectionClass( Pickup_Handler_Location_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Builds a {@see Pickup_Handler} whose {@see Pickup_Handler::location_layer_active()}
	 * seam answers a FIXED value the test controls directly — same "bypass the
	 * registry/provider/settings machinery, this file needs only the outcome" reasoning as
	 * PickupHandlerTest.php's own `Pickup_Handler_Location_Service_Active_Probe`.
	 *
	 * @param bool                  $location_active what `location_layer_active()` answers.
	 * @param string                $plugin_id       the handler's plugin identity — the
	 *                                                gate's own key.
	 * @param Shipping_Plugin|null  $plugin          the constructor's `$plugin` argument.
	 */
	private function handler( bool $location_active, string $plugin_id, ?Shipping_Plugin $plugin ): Pickup_Handler {
		return new Pickup_Handler_Location_Wiring_Probe(
			$location_active,
			$plugin_id,
			$this->source(),
			$this->provider(),
			$this->location(),
			$plugin
		);
	}

	/**
	 * The exact silent degradation the card reports: no `$plugin`, layer active. Must fire
	 * exactly once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fires_when_built_without_plugin_while_the_location_layer_is_active(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->once();

		$this->handler( true, 'plugin_a', null );
	}

	/**
	 * `$plugin` wired — the ordinary, correct case. Must stay completely silent regardless
	 * of whether the layer is active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_silent_when_plugin_is_wired(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->never();

		$this->handler( true, 'plugin_a', $this->plugin() );
	}

	/**
	 * No `$plugin`, but the shop's Location Provider layer is not active — nothing to
	 * degrade FROM, since every `Pickup_Handler` on this shop already addresses by name.
	 * Must stay silent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_silent_when_the_location_layer_is_not_active(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->never();

		$this->handler( false, 'plugin_a', null );
	}

	/**
	 * THE S113 TRAP (gotcha
	 * `a-process-static-once-per-request-gate-checks-only-the-first-plugin`): every plugin
	 * using this framework builds its OWN `Pickup_Handler`, so the gate must be keyed by
	 * plugin id, not a bare process-wide bool. Two DIFFERENT plugins, each built without
	 * `$plugin` while the layer is active, must EACH be reported — not only the first one
	 * constructed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconciles_every_plugin_identity_not_only_the_first(): void {
		self::require_doubles();
		define( 'WP_DEBUG', true );

		Functions\expect( '_doing_it_wrong' )->twice();

		$this->handler( true, 'plugin_a', null );
		$this->handler( true, 'plugin_b', null );
	}
}

/**
 * Probe substituting a fixed {@see Pickup_Handler::location_layer_active()} answer — the
 * one collaborator {@see Pickup_Handler::reconcile_location_context_wiring()} cannot reach
 * through `$plugin` in the exact case this file exercises (`$plugin` being `null`).
 *
 * The fixed answer is assigned BEFORE `parent::__construct()` runs — the constructor
 * itself calls `reconcile_location_context_wiring()`, which reads it via the overridden
 * `location_layer_active()`, so it must already be set by the time that happens.
 */
final class Pickup_Handler_Location_Wiring_Probe extends Pickup_Handler {

	private bool $location_active_answer;

	/**
	 * @param bool                 $location_active_answer what `location_layer_active()`
	 *                                                      answers.
	 * @param string               $plugin_id
	 * @param Point_Source         $source
	 * @param Map_Provider         $map_provider
	 * @param array                $default_location
	 * @param Shipping_Plugin|null $plugin
	 */
	public function __construct(
		bool $location_active_answer,
		string $plugin_id,
		Point_Source $source,
		Map_Provider $map_provider,
		array $default_location,
		?Shipping_Plugin $plugin
	) {
		$this->location_active_answer = $location_active_answer;

		parent::__construct(
			$plugin_id,
			'carrier_pickup_point',
			$source,
			$map_provider,
			$default_location,
			null,
			null,
			[],
			'#06aedd',
			'',
			true,
			false,
			null,
			$plugin
		);
	}

	protected function location_layer_active(): bool {
		return $this->location_active_answer;
	}
}
