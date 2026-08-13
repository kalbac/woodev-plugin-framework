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
		 * Accepted rig aliases for {@see self::FIXTURE_LOCALITY} (Task 15; issue #159 rig
		 * verification, s71) — the DaData ACCOUNT configured for this rig's
		 * `WOODEV_TEST_DADATA_TOKEN` answers RU settlement suggestions with English display
		 * names ("Moscow", not "Москва" — a DaData account-level response-language setting,
		 * unrelated to this framework's own request shape, which sends no `language`
		 * parameter at all). Matching ONLY the Cyrillic constant would make the record-based
		 * addressing path this task adds unreachable on the actual rig, even though the
		 * mechanism itself (locality KEY out, record + resolved identity back in) is
		 * correct — this alias list is what makes the demo visible without weakening the
		 * unit-tested Cyrillic path, which stays exact.
		 */
		private const FIXTURE_LOCALITY_ALIASES = [ 'Москва', 'Moscow' ];

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * Returns every fixture point when the requested locality is this fixture's own
		 * city, or an empty list otherwise (issue #162; Task 15/#159).
		 *
		 * ADDRESSING SOURCE (Task 15; issue #159): when the Location Provider layer
		 * attached a record ({@see \Woodev\Framework\Shipping\Pickup\Point_Query::get_record()}),
		 * this fixture matches the record's OWN settlement name — the browser no longer
		 * sends a bare, DOM-read city string at all; it sends the layer's locality KEY,
		 * opaque to this class, and the framework separately resolves the customer's
		 * current record server-side ({@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::location_context()}).
		 * Falls back to the legacy `$query->get_locality()` string ONLY when no record was
		 * attached — the shape every EXISTING unit test in this repo still builds by hand
		 * (`Point_Query::from_request( [ 'locality' => 'Москва' ] )`, no
		 * `with_location()` call) — so this fixture keeps answering both shapes rather
		 * than breaking a test suite this task did not touch.
		 *
		 * Matching is case-/surrounding-whitespace-insensitive — a real DaData settlement
		 * suggestion's own casing is not guaranteed to match this fixture's literal
		 * — but otherwise exact: no transliteration, no fuzzy/partial matching. Without
		 * this the checkout's `emptyLocality` state (spec V-5) could never be seen on the
		 * rig, only in unit/jest tests — a real carrier integration would filter
		 * server-side the same way, just against its own city list instead of this one
		 * hardcoded string.
		 *
		 * @inheritDoc
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			if ( ! $this->locality_matches( $this->requested_locality_name( $query ) ) ) {
				return [];
			}

			return array_values( array_filter( array_map(
				[ \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, 'from_array' ],
				$this->all_points()
			) ) );
		}

		/**
		 * Resolves the human-readable locality name to match against — the attached
		 * record's own settlement (or, failing that, its display label) when the
		 * Location Provider layer attached one, otherwise the bare `locality` param a
		 * pre-#159 caller still sends. See {@see self::fetch_points()}'s own docblock for
		 * why both shapes are honoured.
		 *
		 * @param \Woodev\Framework\Shipping\Pickup\Point_Query $query The dispatched query.
		 *
		 * @return string|null
		 */
		private function requested_locality_name( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): ?string {
			$record = $query->get_record();

			if ( null === $record ) {
				return $query->get_locality();
			}

			$settlement = $record->settlement();

			if ( null !== $settlement && '' !== $settlement['name'] ) {
				return $settlement['name'];
			}

			return '' !== $record->label() ? $record->label() : null;
		}

		/**
		 * Whether the requested locality names this fixture's own city, ignoring case
		 * and surrounding whitespace.
		 *
		 * @param string|null $locality Requested locality name — see
		 *                               {@see self::requested_locality_name()}; the
		 *                               null-coalesce below covers a query naming neither
		 *                               a record nor a legacy `locality` string.
		 *
		 * @return bool
		 */
		private function locality_matches( ?string $locality ): bool {
			$normalized = mb_strtolower( trim( $locality ?? '' ) );

			foreach ( self::FIXTURE_LOCALITY_ALIASES as $alias ) {
				if ( $normalized === mb_strtolower( $alias ) ) {
					return true;
				}
			}

			return false;
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
