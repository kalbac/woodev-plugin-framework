<?php
/**
 * Woodev_Test_Embedded_Confirm_Point_Source — issue #251 rig finding F4.
 *
 * RIG-ONLY STUB. NOT A PATTERN TO FOLLOW IN A REAL PLUGIN — see the class docblock below
 * for the obligation a real embedded-carrier integration actually has.
 *
 * THE PROBLEM THIS FIXES: under `WOODEV_TEST_PICKUP_EMBEDDED`, the carrier's OWN widget
 * (Почта России, `https://widget.pochta.ru/map/`) renders and lists its own points inside
 * the iframe — this fixture's `Point_Source::fetch_points()` is genuinely never consulted
 * for that half, exactly as `woodev-test-shipping-method.php`'s own docblock says. But the
 * CONFIRMATION round trip is a DIFFERENT call, made by the framework itself, provider-
 * agnostic: `Pickup_Controller::handle_select_request()` always re-fetches the id the
 * browser reports via `Point_Source::fetch_details( $point_id )` — regardless of whether
 * that id came from `woodev/v1`'s own map or from a carrier's embedded widget. The id a
 * customer selects inside the Почта widget is a REAL CARRIER id (e.g. `"43213"`, measured
 * on the rig, spec `docs-internal/specs/2026-08-10-embedded-map-provider-adapter-seam.md`
 * §1 M7) — a value none of this fixture's OTHER `Point_Source` implementations
 * (`Woodev_Test_Bulk_Point_Source`, `Woodev_Test_Viewport_Point_Source`, the two LIVE
 * sources) has ever heard of, so `fetch_details()` returned `null` and the REST endpoint
 * answered `404 woodev_pickup_point_not_found` for EVERY real selection. That made the
 * `WOODEV_TEST_PICKUP_SELECTION_CLOSE`/`WOODEV_TEST_PICKUP_SELECTION_REFRESH_CHECKOUT`
 * constants (also issue #251) unreachable — nothing downstream of a successful
 * confirmation could ever run.
 *
 * THE PRIOR DOCBLOCK CLAIM WAS WRONG. `woodev-test-shipping-method.php`'s own comment on
 * `WOODEV_TEST_PICKUP_EMBEDDED` used to say the resolved `$point_source` is "a dead-looking
 * branch... never actually consulted by anything on the page" — true for `fetch_points()`,
 * false for `fetch_details()`. This class (and the corrected comment alongside its use) is
 * the fix for that wrong claim, not just for the 404.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Embedded_Confirm_Point_Source' ) ) {

	/**
	 * Class Woodev_Test_Embedded_Confirm_Point_Source
	 *
	 * A `Point_Source` whose `fetch_details()` accepts ANY point id and fabricates a
	 * plausible, always-permissive `Pickup_Point` for it — so the rig's confirmation round
	 * trip (`Pickup_Controller::handle_select_request()`, which is the AUTHORITATIVE gate
	 * for every pickup selection, embedded or not — see `map-provider-embedded.js`'s own
	 * "AUTHORITY for this path" docblock section) succeeds regardless of which real Почта
	 * id the carrier's widget handed back to the browser.
	 *
	 * `fetch_points()` returns an empty list unconditionally: under
	 * `Embedded_Map_Provider::owns_chrome()`, the framework's own point-listing REST call
	 * is never made in the first place (the carrier's iframe renders its own list), so this
	 * method exists only to satisfy the interface, not because it is ever reached on the
	 * rig.
	 *
	 * `accepts_cod: true` and `max_weight: null` are chosen so `Constraint_Checker::check()`
	 * always answers `allowed: true` (see that method's own "unknown is permissive" /
	 * `max_weight === null` short-circuit) — a fixture stub gating a real customer's chosen
	 * payment method or cart weight would be inventing a domain rule this class has no basis
	 * for, and would make the reachability fix this class exists for depend on which
	 * gateway happens to be enabled on the rig.
	 *
	 * ⚠️ A REAL EMBEDDED-CARRIER PLUGIN MUST NOT SHIP ANYTHING LIKE THIS. This class exists
	 * ONLY because the rig has no server-side record of a point the customer picked entirely
	 * inside the carrier's own iframe — the framework never saw the widget's `pvzData`
	 * payload, only the browser did. A real plugin's `fetch_details()` MUST perform a
	 * genuine carrier API lookup by the id the customer selected (Почта: `GET
	 * https://widget.pochta.ru/api/pvz/{id}`, the same endpoint
	 * `Woodev_Test_Live_Pochta_Point_Source::fetch_details()` in this very fixture already
	 * demonstrates against real data) — returning a fabricated record for an id nobody
	 * verified would let checkout confirm a pickup point that does not exist. The
	 * confirmation round trip is deliberately the framework's ONE authoritative gate
	 * (`Pickup_Handler::handle_checkout_process()` re-runs it again at order time); this
	 * stub exists to make that gate PASS on the rig for a demo id, never to weaken what it
	 * checks in production.
	 */
	class Woodev_Test_Embedded_Confirm_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

		/**
		 * Fixed placement — the fixture's whole point set is Moscow (see
		 * `Woodev_Test_Bulk_Point_Source::FIXTURE_LOCALITY` and this plugin's
		 * `$default_location`), and no coordinate reaches the customer through this path
		 * anyway (the embedded provider draws no map of its own — see
		 * `map-provider-embedded.js`'s file docblock).
		 */
		private const FIXED_LAT = 55.76;

		/** @see self::FIXED_LAT */
		private const FIXED_LNG = 37.64;

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_VIEWPORT;
		}

		/**
		 * Never actually called on the rig under `Embedded_Map_Provider` (see class
		 * docblock) — returns an empty list rather than fabricating a fake catalogue, since
		 * a customer never sees this source's OWN list, only the carrier widget's.
		 *
		 * @inheritDoc
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			return [];
		}

		/**
		 * Fabricates a permissive `Pickup_Point` for ANY id — see the class docblock for
		 * why, and for the obligation a real plugin has instead. Never returns `null`: an
		 * empty/whitespace-only id is the one case this class refuses, since
		 * `Pickup_Point::from_array()` itself would reject an empty `id` regardless.
		 *
		 * @inheritDoc
		 */
		public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			if ( '' === trim( $point_id ) ) {
				return null;
			}

			return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array(
				[
					'id'          => $point_id,
					// Russian, matching every other fixture-authored display string in this
					// plugin — this label exists only to prove the round trip completed, not
					// to look like a real Почта point (a real adapter builds the real name —
					// see `WoodevPochtaEmbed.toPoint` — but that name never reaches the
					// server; only the id does).
					'name'        => 'Тестовая точка (embedded) №' . $point_id,
					'lat'         => self::FIXED_LAT,
					'lng'         => self::FIXED_LNG,
					'address'     => 'Фикстура: подтверждение для внешнего id ' . $point_id,
					'type'        => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
					// Always permissive — see the class docblock for why.
					'accepts_cod' => true,
					'max_weight'  => null,
				]
			);
		}
	}
}
