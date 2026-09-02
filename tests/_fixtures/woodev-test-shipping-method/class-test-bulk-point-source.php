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
		 * The fixture's localities (issue #270) — every fixture point's own `locality`
		 * field (see fixture-points.php) is one of these exact canonical (Cyrillic) keys.
		 * Each canonical name maps to its accepted rig aliases (Task 15; issue #159 rig
		 * verification, s71) — the DaData ACCOUNT configured for this rig's
		 * `WOODEV_TEST_DADATA_TOKEN` answers RU settlement suggestions with English display
		 * names ("Moscow", not "Москва" — a DaData account-level response-language setting,
		 * unrelated to this framework's own request shape, which sends no `language`
		 * parameter at all). Matching ONLY the Cyrillic key would make the record-based
		 * addressing path unreachable on the actual rig, even though the mechanism itself
		 * (locality KEY out, record + resolved identity back in) is correct — the alias
		 * lists are what make the demo visible without weakening the unit-tested Cyrillic
		 * path, which stays exact.
		 *
		 * The "Москва" => [ 'Москва', 'Moscow' ] pair is the only one actually confirmed
		 * against a live DaData response (s71). The "Санкт-Петербург" and "Краснодар"
		 * English aliases below are PLAUSIBLE, UNVERIFIED spellings — nobody has checked
		 * what this rig's DaData account actually returns for those two settlements; see
		 * task #270's report for the caveat.
		 */
		private const LOCALITY_ALIASES = [
			'Москва'          => [ 'Москва', 'Moscow' ],
			'Санкт-Петербург' => [ 'Санкт-Петербург', 'Saint Petersburg', 'St. Petersburg', 'St Petersburg' ],
			'Краснодар'       => [ 'Краснодар', 'Krasnodar' ],
		];

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * Returns the fixture points BELONGING TO the requested locality (matched via
		 * {@see self::resolve_locality()} and then filtered against each point's own
		 * `locality` field — issue #270), or an empty list when the requested locality
		 * resolves to none of {@see self::LOCALITY_ALIASES} (issue #162; Task 15/#159).
		 *
		 * ADDRESSING SOURCE (Task 15; issue #159): when the Location Provider layer
		 * attached a record ({@see \Woodev\Framework\Shipping\Pickup\Point_Query::get_record()}),
		 * this fixture matches the record's OWN settlement name. On THIS fixture's own
		 * checkout — whose `Pickup_Handler` is wired with its owning plugin, see
		 * `woodev-test-shipping-method.php`'s own construction call — the browser sends
		 * the layer's locality KEY, opaque to this class, rather than a bare DOM-read city
		 * string, and the framework separately resolves the customer's current record
		 * server-side ({@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::location_context()}).
		 * That is a per-plugin WIRING CHOICE, not a framework-wide guarantee (issue #746):
		 * a `Pickup_Handler` built without its plugin still degrades — silently unless the
		 * shop's Location Provider layer is active — to the exact bare-name shape this
		 * class also answers. This class falls back to the legacy `$query->get_locality()`
		 * string ONLY when no record was attached — the shape every EXISTING unit test in
		 * this repo still builds by hand (`Point_Query::from_request( [ 'locality' =>
		 * 'Москва' ] )`, no `with_location()` call), and the same shape a `Pickup_Handler`
		 * without its plugin degrades to in production — so this fixture keeps answering
		 * both shapes rather than breaking a test suite this task did not touch.
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
			$locality = $this->resolve_locality( $this->requested_locality_name( $query ) );

			if ( null === $locality ) {
				return [];
			}

			$points_for_locality = array_filter(
				$this->all_points(),
				static fn( array $payload ): bool => $locality === ( $payload['locality'] ?? null )
			);

			return array_values( array_filter( array_map(
				[ \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, 'from_array' ],
				$points_for_locality
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
		 * Resolves the requested locality name to one of this fixture's canonical
		 * (Cyrillic) locality keys via {@see self::LOCALITY_ALIASES}, ignoring case and
		 * surrounding whitespace, or `null` when it names none of them.
		 *
		 * Matching is case-/whitespace-insensitive but otherwise exact: no
		 * transliteration, no fuzzy/partial matching (issue #162's explicit decision,
		 * unchanged by #270's move to per-locality filtering).
		 *
		 * @param string|null $locality Requested locality name — see
		 *                               {@see self::requested_locality_name()}; the
		 *                               null-coalesce below covers a query naming neither
		 *                               a record nor a legacy `locality` string.
		 *
		 * @return string|null The canonical locality key, or null if unmatched.
		 */
		private function resolve_locality( ?string $locality ): ?string {
			$normalized = mb_strtolower( trim( $locality ?? '' ) );

			foreach ( self::LOCALITY_ALIASES as $canonical => $aliases ) {
				foreach ( $aliases as $alias ) {
					if ( $normalized === mb_strtolower( $alias ) ) {
						return $canonical;
					}
				}
			}

			return null;
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
		 * Every fixture point across every locality this fixture serves — every field
		 * populated. {@see self::fetch_points()} is what filters this down to the
		 * requested locality; this method itself does not filter.
		 *
		 * SP-map Task 1: the fixture was grown from the original 5 static Moscow points
		 * (still present, ids included, as the first 5 entries) to ~49 Moscow points
		 * across 2 types, including a co-located pair on identical coordinates — see the
		 * docblock in fixture-points.php for why. Issue #270 added a second and third
		 * locality (Санкт-Петербург, Краснодар) on top, each with its own real
		 * coordinates and ids — see that file's own docblock. Delegated to a standalone
		 * data file so the unit suite can assert on its shape without loading the whole
		 * plugin.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function all_points(): array {
			return require __DIR__ . '/fixture-points.php';
		}
	}
}
