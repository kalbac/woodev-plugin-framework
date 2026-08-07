<?php
/**
 * Woodev_Test_Bulk_Point_Source — the rig's STRATEGY_BULK fixture Point_Source.
 *
 * Extracted to its own file (unlike `Woodev_Test_Viewport_Point_Source`, still declared
 * inline in `woodev-test-shipping-method.php`) so a PHPUnit unit test can `require_once`
 * it directly, right after the `Point_Source` interface, WITHOUT going through the
 * fixture plugin's full Platform v2 load path: `Woodev_Plugin_Bootstrap`'s resolver is a
 * process-wide singleton with a one-shot `load_plugins()` latch (see
 * `Woodev_Plugin_Bootstrap::instance()`/`Framework_Resolver::$loaded`), so once any other
 * test in the same PHPUnit run (e.g. `BootstrapTest`) has loaded it, a second
 * registration+load attempt from this fixture is silently a no-op — order-dependent and
 * unusable as a unit-test seam.
 *
 * `woodev_test_shipping_method_plugin_init()` requires this file the same way it used to
 * declare the class inline, at the same point in its own execution — the `Point_Source`
 * interface is guaranteed autoloadable by the time that callback runs (see its own
 * docblock), so production behaviour is unchanged by this split.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Bulk_Point_Source' ) ) {

	/**
	 * Class Woodev_Test_Bulk_Point_Source
	 *
	 * STRATEGY_BULK fixture source (Yandex/CDEK shape): every point for the
	 * requested locality is returned in one call, every field populated up front.
	 * Two of the five points are deliberately named so a human driving the rig can
	 * find them on the live map: one refuses cash on delivery (COD gating), one
	 * caps the accepted parcel weight at 1 kg (weight-limit gating).
	 */
	class Woodev_Test_Bulk_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

		/** Point id that refuses cash on delivery — exercises COD gating on the rig. */
		public const COD_REFUSING_POINT_ID = 'FIX-BULK-2';

		/** Point id capped at 1000 g — exercises the weight-limit rule on the rig. */
		public const WEIGHT_LIMITED_POINT_ID = 'FIX-BULK-4';

		/**
		 * The fixture's one static "city" — every fixture point's own `locality` field
		 * (see fixture-points.php) is this exact string.
		 */
		private const FIXTURE_LOCALITY = 'Москва';

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * Returns every fixture point when the requested locality is this fixture's own
		 * city, or an empty list otherwise (issue #162).
		 *
		 * The framework guarantees `$query->get_locality()` is non-null for a
		 * STRATEGY_BULK source (see the Point_Source interface docblock); a real
		 * carrier would filter server-side by that locality. Matching is
		 * case-/surrounding-whitespace-insensitive — a browser city input or a
		 * REST client is not guaranteed to send back the exact casing this fixture
		 * emits — but otherwise exact: no transliteration, no fuzzy/partial matching.
		 * Without this the checkout's `emptyLocality` state (spec V-5) could never be
		 * seen on the rig, only in unit/jest tests — a real carrier integration
		 * would filter server-side the same way, just against its own city list
		 * instead of this one hardcoded string.
		 *
		 * @inheritDoc
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			if ( ! $this->locality_matches( $query->get_locality() ) ) {
				return [];
			}

			return array_values( array_filter( array_map(
				[ \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, 'from_array' ],
				$this->all_points()
			) ) );
		}

		/**
		 * Whether the requested locality names this fixture's own city, ignoring case
		 * and surrounding whitespace.
		 *
		 * @param string|null $locality Requested locality, guaranteed non-null by the
		 *                               framework for a STRATEGY_BULK source (see
		 *                               {@see self::fetch_points()}); the null-coalesce
		 *                               below is defensive only.
		 *
		 * @return bool
		 */
		private function locality_matches( ?string $locality ): bool {
			return mb_strtolower( trim( $locality ?? '' ) ) === mb_strtolower( self::FIXTURE_LOCALITY );
		}

		/**
		 * @inheritDoc
		 */
		public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			foreach ( $this->all_points() as $payload ) {
				if ( $point_id === $payload['id'] ) {
					return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array( $payload );
				}
			}

			return null;
		}

		/**
		 * The fixture's Moscow points — every field populated.
		 *
		 * SP-map Task 1: the fixture was grown from the original 5 static points
		 * (still present, ids included, as the first 5 entries) to ~49 points
		 * across 2 types, including a co-located pair on identical coordinates —
		 * see the docblock in fixture-points.php for why. Delegated to a standalone
		 * data file so the unit suite can assert on its shape without loading the
		 * whole plugin.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function all_points(): array {
			return require __DIR__ . '/fixture-points.php';
		}
	}
}
