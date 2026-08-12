<?php
/**
 * Woodev_Test_Location_Adapter — the rig's fixture Location_Adapter.
 *
 * Location Provider layer, block PR-C rig-visibility pull-forward (docs-internal/plans/
 * 2026-08-12-location-provider-plan.md, Task 13/PR-D scope, pulled forward as the minimum
 * needed to make PR-C's rig checklist performable): every shipping plugin that opts into the
 * layer ({@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()} `true`)
 * MUST supply an adapter — {@see \Woodev\Framework\Shipping\Location\Location_Adapter}'s own
 * docblock, "Minimum for a not-yet-written plugin: one adapter + the declaration."
 *
 * Extracted to its own file — same reasoning as `class-test-bulk-point-source.php`'s own
 * docblock — so a PHPUnit unit test can `require_once` it directly, right after the interface
 * and value object it depends on, without going through the fixture plugin's full Platform v2
 * load path.
 *
 * `woodev_test_shipping_method_plugin_init()` requires this file at the same point in its own
 * execution where the class used to be declared inline — the `Location_Adapter` interface is
 * guaranteed autoloadable by the time that callback runs (gotcha
 * `fixture-classes-must-live-inside-plugin-init`), so production behaviour is unchanged by
 * this split.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Location_Adapter' ) ) {

	/**
	 * Class Woodev_Test_Location_Adapter
	 *
	 * Honest and trivial by design (this is a fixture, not a carrier): resolves EVERY
	 * record to a deterministic, visibly-fixture-shaped identity derived from the
	 * record's own namespaced key — no domain logic beyond that, no HTTP call, nothing
	 * to cache-bust. The one exception is {@see self::UNSERVED_SETTLEMENT}, a real town
	 * name chosen so it is a plausible DaData suggestion on the rig, letting the
	 * operator reach the "this carrier does not serve this locality" (`null`) path by
	 * typing a real address instead of a string that could never come back from a real
	 * suggestion API.
	 */
	class Woodev_Test_Location_Adapter implements \Woodev\Framework\Shipping\Location\Location_Adapter {

		/**
		 * Settlement name this fixture carrier refuses to serve — the rig's own way to
		 * reach the "does not serve" (`null` resolve) path. Урюпинск (Volgograd Oblast)
		 * is a genuine Russian town, so it is reachable through a real DaData settlement
		 * suggestion rather than a fabricated string no suggestion API would ever return.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const UNSERVED_SETTLEMENT = 'Урюпинск';

		/**
		 * @inheritDoc
		 *
		 * @since 2.0.2
		 */
		public function resolve( \Woodev\Framework\Shipping\Location\Location_Record $record ) {
			$settlement = $record->settlement();
			$name       = $settlement['name'] ?? null;

			if ( self::UNSERVED_SETTLEMENT === $name ) {
				return null;
			}

			// Deliberately dumb: the record's own namespaced key, prefixed so it reads as
			// obviously fixture-shaped in a debugger or log rather than passing for a real
			// carrier identity.
			return 'fixture-carrier:' . $record->key();
		}
	}
}
