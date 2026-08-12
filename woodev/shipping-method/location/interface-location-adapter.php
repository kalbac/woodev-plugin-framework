<?php
/**
 * Woodev Location Adapter Interface
 *
 * The mandatory per-plugin translator behind the Location Provider layer's adapter
 * contract (Task 5; spec §4.3): a location record → the carrier's own identity
 * (`city_code`, `geo_id`, a ФИАС-derived id, postal index — whatever the carrier's API
 * needs). Every shipping plugin that participates in the layer (declares
 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()} `true`)
 * MUST override {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_location_adapter()}
 * to return one — including the plugin that brought the active provider itself.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Adapter' ) ) :

	/**
	 * Per-plugin location-record → carrier-identity translator.
	 *
	 * Implementations are plugin-owned and carrier-specific; the framework never
	 * constructs one — {@see Shipping_Plugin::get_location_adapter()} does — and
	 * never inspects what {@see self::resolve()} returns. The framework only:
	 * runs it lazily, at rate/points calculation time rather than at selection
	 * time (spec D9); caches BOTH outcomes — a resolved identity and a `null`
	 * "does not serve" answer — per `(locality_key, plugin_id)` in the session
	 * (see {@see Location_Resolution_Cache}); and treats a THROW as a distinct,
	 * uncached, retryable outcome (see below).
	 *
	 * @since 2.0.2
	 */
	interface Location_Adapter {

		/**
		 * Translates a neutral location record into this carrier's own identity.
		 *
		 * The return value is deliberately OPAQUE to the framework — a `city_code`
		 * string, an int `geo_id`, an array of several fields, whatever this
		 * carrier's API needs. The framework stores it, hands it back on a cache
		 * hit, and never looks inside it.
		 *
		 * **`null` is a legitimate, first-class answer**, not an error: it means
		 * "this carrier does not serve this locality" (e.g. a village outside the
		 * carrier's delivery network). The framework caches a `null` resolve
		 * exactly like it caches a real identity — a re-read must not call this
		 * method again — while still respecting the empty-key discipline (a
		 * failed resolve is cached as an explicit failure MARKER, never written
		 * as if it were an ordinary value that merely happens to be empty; see
		 * {@see Location_Resolution_Cache}).
		 *
		 * **Throwing is reserved for genuinely TRANSIENT failures** — a timeout
		 * or a 5xx from the carrier's own API, something that might succeed on
		 * the very next request. The framework treats any thrown `\Throwable` as
		 * retryable: it is logged, the outcome is deliberately NOT cached (unlike
		 * a `null` "does not serve" answer), and the very next call to
		 * {@see Location_Resolution_Cache::resolve_for()} for the same
		 * `(locality_key, plugin_id)` calls this method again. Do NOT throw for
		 * "this carrier does not serve this locality" — return `null` for that;
		 * throwing for it would defeat the failure cache and hit the carrier's
		 * API on every single rate/points calculation for a locality that will
		 * never resolve.
		 *
		 * **Called on any request that calculates shipping rates or fetches
		 * pickup points** — not merely at checkout. A cache hit avoids a second
		 * call for the same locality within the same session, but the FIRST call
		 * for a given locality always reaches this method, so it must either be
		 * cheap on its own (a local dictionary lookup) or do its own HTTP-level
		 * caching (e.g. a transient) if it talks to a remote API — the framework
		 * makes no guarantee about how many times a genuinely new locality will
		 * be resolved across a fleet of concurrent shoppers.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The customer's current, provider-neutral
		 *                                 location record.
		 *
		 * @return mixed|null This carrier's own identity for the record's
		 *                     locality (opaque to the framework), or `null` when
		 *                     this carrier does not serve it.
		 *
		 * @throws \Throwable On a transient failure (e.g. an API timeout) — see
		 *                    above. Reserved for genuinely retryable conditions.
		 */
		public function resolve( Location_Record $record );
	}

endif;
