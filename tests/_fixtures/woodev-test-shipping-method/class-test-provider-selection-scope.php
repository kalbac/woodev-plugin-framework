<?php
/**
 * Woodev_Test_Provider_Selection_Scope — the rig's own
 * {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope} fixture (review finding
 * F4 on issue #159/PR #312).
 *
 * Replaces the rig's previous, INDEPENDENT {@see Selection_Scope} fixture
 * (`Woodev_Test_Selection_Scope`, `billing_state` region-code vocabulary — see that class'
 * own former docblock in git history) as the seam {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler}
 * is constructed with. `Provider_Selection_Scope` had no caller anywhere outside its own unit
 * test (`ProviderSelectionScopeTest`) — gotcha `built-on-both-sides-with-no-caller-in-the-middle`,
 * which this seam had already hit four times — so the rig never exercised the "remembered
 * selection addressed by the Location Provider layer's own key" half of #159 at all. This
 * fixture gives it a real caller.
 *
 * DEMONSTRATES THE WRITE/READ VOCABULARY AGREEMENT {@see Provider_Selection_Scope}'s own
 * docblock now warns about (review finding F4): {@see self::locality_for_point()} answers
 * from the SAME {@see Location_Service} {@see Provider_Selection_Scope::current_locality()}
 * (inherited, `final`) reads — literally `return $this->current_locality();` — rather than
 * from this fixture's OWN points' `locality` field (the human city name, "Москва") or any
 * other carrier-owned vocabulary. That is deliberate and safe here, not a shortcut: this
 * fixture's `Point_Source` implementations are all addressed BY LOCALITY (bulk strategy;
 * `Point_Query::with_location()`, Task 15), so every point confirmed through the picker
 * necessarily belongs to whatever locality was current at fetch/confirm time — the same
 * answer `current_locality()` gives. A plugin whose points are NOT locality-addressed this
 * way must derive `locality_for_point()` differently — see
 * `Provider_Selection_Scope`'s own docblock for the general rule and the failure mode of
 * getting it wrong.
 *
 * Extracted to its own file, `require_once`'d from `woodev_test_shipping_method_plugin_init()`
 * (not declared inline there, and not at this file's top level) for the same reason
 * `Woodev_Test_Bulk_Point_Source` is: a class `extends`-ing a `Woodev\Framework\*` symbol can
 * only be DECLARED once the bootstrap has selected the framework copy and registered its
 * autoloader — see that file's own docblock and gotcha
 * `fixture-classes-must-live-inside-plugin-init`.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Provider_Selection_Scope' ) ) {

	/**
	 * Class Woodev_Test_Provider_Selection_Scope
	 *
	 * The single fixture shipping method (`woodev_test_shipping`) is not itself
	 * type-specific, so it maps to
	 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Scope::TYPE_ANY} — every other
	 * method id (a courier method a real plugin might also register) gets `null`,
	 * meaning no selection is ever restored for it. Same convention the fixture's
	 * former `Woodev_Test_Selection_Scope` used.
	 */
	class Woodev_Test_Provider_Selection_Scope extends \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope {

		/**
		 * @inheritDoc
		 */
		public function session_key(): string {
			// An installed-site data contract owned by THIS plugin (spec §3.2) — a real
			// plugin's own key, e.g. Yandex's `chosen_yandex_pickup_point`, not a
			// framework-coined one.
			return 'woodev_test_provider_pickup_selection';
		}

		/**
		 * @inheritDoc
		 *
		 * See this file's own docblock for why reusing {@see self::current_locality()}
		 * here — rather than reading this fixture's points' own `locality` field — is the
		 * write/read vocabulary agreement {@see \Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope}
		 * requires, not a shortcut.
		 */
		public function locality_for_point( \Woodev\Framework\Shipping\Pickup\Pickup_Point $point ): string {
			return $this->current_locality();
		}

		/**
		 * @inheritDoc
		 */
		public function type_for_method( string $method_id ): ?string {
			// Literal method id (= Woodev_Test_Shipping_Method::METHOD_ID) — same
			// reasoning as the checkout-fields block's own literal: that class is not
			// yet loaded when this scope is constructed.
			if ( 'woodev_test_shipping' !== $method_id ) {
				return null;
			}

			return \Woodev\Framework\Shipping\Pickup\Selection_Scope::TYPE_ANY;
		}
	}
}
