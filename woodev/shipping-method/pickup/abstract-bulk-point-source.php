<?php
/**
 * Woodev Abstract Bulk Point Source
 *
 * Base implementation of {@see Point_Source} for a `STRATEGY_BULK` carrier (Yandex,
 * CDEK: a whole locality is fetched in one call). Card #144.
 *
 * WHY `fetch_details()` IS NOT BUILT ON TOP OF `fetch_points()` — the card's original
 * proposal, ruled out by reading all four `STRATEGY_BULK` fixtures against the real
 * call sites (`Pickup_Controller::handle_select_request()`,
 * `Pickup_Handler::handle_checkout_process()`): both call `fetch_details( $point_id )`
 * with NO {@see Point_Query} in hand — the interface itself declares no way to build
 * one from a bare point id (there is no locality to put in it). `fetch_points()`
 * requires a query whose `get_locality()` the framework guarantees non-null for a
 * bulk source, so a base class cannot manufacture one to route through the public
 * method. All four existing implementations independently confirm this by NEVER
 * calling their own `fetch_points()` from `fetch_details()` — each instead scans a
 * private raw data source directly. That scan-and-match shape, not "call
 * fetch_points()", is the actual convergence, and is what this class abstracts.
 *
 * WHAT GENUINELY CONVERGES across the four fixtures (`Woodev_Test_Bulk_Point_Source`,
 * `Woodev_Test_Live_Yandex_Point_Source`, `Woodev_Realistic_Point_Source`,
 * `Woodev_Yandex_Pilot_Point_Source`): `get_strategy()` always returns
 * `STRATEGY_BULK`; `fetch_details()` is always a linear scan over the same raw
 * universe `fetch_points()` draws from, comparing a raw id, normalizing only the
 * matched entry, and returning `null` on no match (never throwing "not found" —
 * only a genuine transport/API failure throws, exactly as {@see Point_Source}
 * requires). What diverges is the SHAPE of one raw entry: three fixtures' raw
 * payloads are already the framework's normalized array shape
 * ({@see Pickup_Point::from_array()}'s own contract); the live Yandex fixture's raw
 * payloads are the carrier's own API shape and need a carrier-specific mapping
 * step. {@see self::normalize_bulk_point()} is the seam for that divergence —
 * left in place even though only one of today's four consumers needs it, per this
 * codebase's rule that an override hook does not need a second consumer to earn
 * its place.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Abstract_Bulk_Point_Source' ) ) :

	/**
	 * Base class for a `STRATEGY_BULK` {@see Point_Source}.
	 *
	 * `get_strategy()` and `fetch_details()` are `final` on purpose, and the escape hatch is
	 * to NOT extend this class: a carrier whose API has a real point-by-id endpoint (CDEK's
	 * `/deliverypoints` takes an arbitrary parameter set, so a by-code lookup is one HTTP
	 * call rather than a scan) implements {@see Point_Source} directly. It gives up nothing
	 * by doing so — overriding `fetch_details()` would already make
	 * {@see self::bulk_point_id()} and {@see self::normalize_bulk_point()} dead, since they
	 * exist only to serve the scan, leaving a one-line `get_strategy()` as the entire
	 * inheritance. `final` therefore costs a subclass nothing and buys the reader a class
	 * that means exactly one thing: THIS is the scan.
	 *
	 * A subclass supplies {@see self::raw_bulk_points()} — the raw entries
	 * `fetch_points()` itself would filter down to a locality — and this class
	 * answers `get_strategy()` and `fetch_details()` on top of it. `fetch_points()`
	 * stays entirely the subclass's own: locality resolution (alias tables, the
	 * Location Provider record vs. legacy string fallback, an unknown-locality
	 * empty list) is carrier/fixture domain logic, not a framework mechanism, and
	 * this class does not touch it.
	 *
	 * @since 2.0.2
	 */
	abstract class Abstract_Bulk_Point_Source implements Point_Source {

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		final public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Scans {@see self::raw_bulk_points()} for the entry whose
		 * {@see self::bulk_point_id()} matches, normalizing only that one entry via
		 * {@see self::normalize_bulk_point()} — the same "match on the raw id, build
		 * the point only for the winner" shape every existing fixture already uses,
		 * so a normalization step expensive enough to matter (schedule flattening,
		 * a per-record icon lookup) never runs for an entry that was never asked
		 * for.
		 *
		 * @since 2.0.2
		 */
		final public function fetch_details( string $point_id ): ?Pickup_Point {
			foreach ( $this->raw_bulk_points() as $raw_point ) {
				if ( $point_id !== $this->bulk_point_id( $raw_point ) ) {
					continue;
				}

				return $this->normalize_bulk_point( $raw_point );
			}

			return null;
		}

		/**
		 * Returns every raw point entry this source can currently answer a
		 * `fetch_details()` lookup against — the same raw universe `fetch_points()`
		 * filters by locality, in whatever shape is native to the subclass (a
		 * plain in-memory array for a static fixture, a cached carrier API
		 * response for a live one).
		 *
		 * A carrier whose bulk-list API is itself locality-scoped (this
		 * framework's own reference Yandex client has no separate "point by id"
		 * endpoint — only a per-locality list) answers this from whatever
		 * locality it currently has cached; nothing here promises a cross-locality
		 * universe, and no existing implementation needs one.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, mixed> Raw entries, in this source's own raw shape.
		 *
		 * @throws \Woodev_API_Exception On a carrier transport, authentication, or
		 *                                API error — see {@see Point_Source::fetch_details()}.
		 */
		abstract protected function raw_bulk_points(): array;

		/**
		 * Extracts one raw entry's carrier point id, for the identity comparison
		 * {@see self::fetch_details()} runs against its `$point_id` argument.
		 *
		 * Default: a plain top-level `id` key, cast to string — the shape every
		 * existing fixture's raw entry already carries, including the live Yandex
		 * one (whose raw entry is the carrier's own record, not yet mapped to
		 * {@see Pickup_Point::from_array()}'s shape, but which still names its id
		 * the same way). Override only if a raw entry's id lives somewhere else
		 * (nested, or under a different key).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw_point One entry from {@see self::raw_bulk_points()}.
		 *
		 * @return string|null Null when the entry is not an array or carries no id.
		 */
		protected function bulk_point_id( $raw_point ): ?string {
			return is_array( $raw_point ) && isset( $raw_point['id'] ) ? (string) $raw_point['id'] : null;
		}

		/**
		 * Normalizes one matched raw entry into a {@see Pickup_Point}.
		 *
		 * Default: the entry is already in the framework's normalized array shape
		 * and is handed straight to {@see Pickup_Point::from_array()} — true for
		 * every fixture except the live Yandex one, which overrides this to run
		 * its own carrier-shape mapping first.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw_point The raw entry {@see self::fetch_details()} matched.
		 *
		 * @return Pickup_Point|null Null when the entry does not build a valid point.
		 */
		protected function normalize_bulk_point( $raw_point ): ?Pickup_Point {
			return is_array( $raw_point ) ? Pickup_Point::from_array( $raw_point ) : null;
		}
	}

endif;
