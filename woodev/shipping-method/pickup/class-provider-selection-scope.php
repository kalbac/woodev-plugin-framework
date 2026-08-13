<?php
/**
 * Woodev Provider Selection Scope
 *
 * The framework's own, location-provider-backed {@see Selection_Scope} implementation
 * (Task 15; issue #159; spec §4.5.4-5). Answers ONLY {@see Selection_Scope::current_locality()}
 * — the ONE question the Location Provider layer (Tasks 1-14) can answer generically, the
 * same way for every plugin that opts into it. `session_key()`, `locality_for_point()` and
 * `type_for_method()` stay abstract: they are, and remain, plugin-owned domain knowledge —
 * see {@see Selection_Scope}'s own docblock for why the framework must never coin any of
 * the three itself.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

use Woodev\Framework\Shipping\Location\Location_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Provider_Selection_Scope' ) ) :

	/**
	 * A {@see Selection_Scope} whose {@see self::current_locality()} is backed by
	 * {@see Location_Service::get_customer_record()} instead of a plugin's own,
	 * hand-rolled read of `WC()->customer`/`WC()->session`.
	 *
	 * NOT a drop-in replacement for a plugin's existing scope — a plugin that already
	 * has one is free to keep it (their session key is theirs, gotcha
	 * `session-key-vs-order-meta-prefix`). This class exists for plugins that DO
	 * participate in the Location Provider layer
	 * ({@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()}
	 * `true`) and want their pickup-selection persistence keyed by the SAME locality
	 * the layer already tracks, without re-deriving it.
	 *
	 * THE EMPTY-KEY DISCIPLINE COMES FOR FREE (gotcha `an-empty-domain-key-is-not-a-key`):
	 * {@see Location_Service::get_customer_record()} returns `null` whenever the
	 * customer has no record yet (no default policy configured, or a `geoip`/`fixed`
	 * miss), and this class turns exactly that into `''` — the same "the seam is
	 * refusing to answer, not naming a locality" sentinel {@see Pickup_Selection}
	 * already refuses on both the write and the read side. Nothing extra is needed
	 * here for that guarantee to hold.
	 *
	 * THE PROVIDER-SWITCH MISS COMES FOR FREE TOO (spec D5): the key
	 * {@see Location_Record::key()} carries is namespaced `provider_id:native_id`.
	 * Switching the store's active location provider changes that namespace for every
	 * NEW record the layer resolves, so a point {@see Pickup_Selection::remember()}
	 * stored under the old provider's key is never recalled under the new one — the
	 * map simply misses, the safe outcome. This class does not implement that
	 * guarantee itself; it inherits it by asking {@see Location_Service} for the
	 * customer's CURRENT record on every call, never caching a key across a provider
	 * switch.
	 *
	 * ============================================================================
	 * IMPLEMENTOR OBLIGATION — {@see self::locality_for_point()} MUST SPEAK THE SAME
	 * VOCABULARY AS {@see self::current_locality()} (review finding F4). This is the
	 * ONE thing a subclass MUST get right and the framework CANNOT check for it:
	 * `current_locality()` is `final` and always answers a Location Provider layer key
	 * (`provider_id:native_id`, e.g. `dadata:0c5b2444-...`) — but `locality_for_point()`
	 * stays abstract, plugin-owned domain knowledge, exactly like it is on
	 * {@see Selection_Scope} directly. If a subclass derives `locality_for_point()`
	 * from the carrier's OWN city vocabulary (a `city_id`, a `geo_id` — what
	 * {@see Selection_Scope}'s own docblock explicitly invites for a plugin that does
	 * NOT extend this class) instead of from this SAME layer, `remember()` and
	 * `recall()` key off two vocabularies that never match: a point is ALWAYS written
	 * under the carrier's key and ALWAYS read back under the Location Provider key
	 * (or vice versa), so nothing is EVER restored — silently, with no error, no
	 * warning, just a map that always looks like the customer never picked anything
	 * before. The safe pattern is to derive `locality_for_point()` from THIS SAME
	 * service too — e.g. `return $this->current_locality();` when the point being
	 * confirmed necessarily belongs to the customer's current locality (true for any
	 * `Point_Source` addressed by the SAME record via {@see Point_Query::with_location()}),
	 * or, when the point carries its own resolved identity, from
	 * {@see Location_Record::key()} built the same way {@see self::current_locality()}
	 * builds it. Never mix the two vocabularies within one subclass.
	 * ============================================================================
	 *
	 * @since 2.0.2
	 */
	abstract class Provider_Selection_Scope implements Selection_Scope {

		/**
		 * The Location Provider layer's service façade this scope reads
		 * `current_locality()` through.
		 *
		 * @since 2.0.2
		 * @var Location_Service
		 */
		private Location_Service $location_service;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Service|null $location_service The Location Provider layer's
		 *                                                 service façade; defaults to a
		 *                                                 fresh instance, matching every
		 *                                                 other framework consumer of
		 *                                                 this façade (e.g.
		 *                                                 {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_location_service()}).
		 *                                                 A test injects a fake/probe
		 *                                                 exactly as
		 *                                                 {@see \Woodev\Tests\Unit\Shipping\Location\LocationServiceTest}
		 *                                                 already does for
		 *                                                 {@see Location_Service} itself.
		 */
		public function __construct( ?Location_Service $location_service = null ) {
			$this->location_service = $location_service ?? new Location_Service();
		}

		/**
		 * Gets the Location Provider layer's service façade this scope was
		 * constructed with — a `protected` accessor, not a bare property read, so a
		 * subclass may reuse the SAME instance (e.g. inside its own
		 * {@see self::locality_for_point()}) rather than constructing a second one.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Service
		 */
		protected function location_service(): Location_Service {
			return $this->location_service;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Returns the customer's CURRENT locality key
		 * ({@see \Woodev\Framework\Shipping\Location\Location_Record::key()}) when
		 * {@see Location_Service::get_customer_record()} answers one, `''` otherwise —
		 * `''` meaning exactly what it means everywhere else in this seam: the layer
		 * is refusing to answer, never a locality named `''`.
		 *
		 * `final`: this is the one behaviour this class exists to fix in place. A
		 * plugin wanting a DIFFERENT `current_locality()` should implement
		 * {@see Selection_Scope} directly instead of extending this class for the
		 * other three methods alone.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		final public function current_locality(): string {
			$current = $this->location_service->get_customer_record();

			return null !== $current ? $current['record']->key() : '';
		}
	}

endif;
