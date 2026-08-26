<?php
/**
 * Woodev Location Service
 *
 * The single entry point every other framework layer (REST, checkout config,
 * pickup) uses to talk to the Location Provider layer (Task 6; spec §4.1, D2,
 * D9, D15). Composes the pieces Tasks 1-5 already built — the provider
 * registry, the dual customer-location store, and the lazy adapter
 * resolution cache — into one façade; it owns no storage and no provider
 * logic of its own, and never reimplements any of theirs.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Service' ) ) :

	/**
	 * Façade over the Location Provider layer's PHP-side subsystems.
	 *
	 * @since 2.0.2
	 */
	class Location_Service {

		/**
		 * Filter tag: lets a plugin override the D15 chain's resolved provider
		 * for one level (chosen → bundled fallback → null — see
		 * {@see self::provider_for_level()}). Receives (and must return) a
		 * {@see Location_Provider} or `null`. Left in place even though
		 * nothing in this codebase consumes it yet (project preference:
		 * extension hooks are not gated on having a consumer) — this is the
		 * one genuinely NEW decision surface this task introduces (the
		 * per-level chain resolution), which is why this façade adds exactly
		 * this one hook and no others.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_PROVIDER_FOR_LEVEL = 'woodev_location_provider_for_level';

		/**
		 * Filters the FINAL resolved country for the checkout-field -> WooCommerce-store-setting
		 * -> `RU` fallback chain (issue #296) — see {@see self::resolve_default_country()} for the
		 * full chain and why this filter runs on every resolution, not only when the store's own
		 * base location is unusable (PR #320 review, finding 2). Left in place even though nothing
		 * in this codebase consumes it yet (project preference: extension hooks are not gated on
		 * having a consumer).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_DEFAULT_COUNTRY = 'woodev_location_default_country';

		/**
		 * Filter tag: overrides {@see self::cached_region_lookup()}'s cache TTL
		 * (issue #551) — see that method's own docblock for the full
		 * reasoning and default.
		 *
		 * @since 2.1.0
		 * @var string
		 */
		public const FILTER_REGION_ANCESTOR_CACHE_TTL = 'woodev_location_region_ancestor_cache_ttl';

		/**
		 * Transient key prefix {@see self::cached_region_lookup()} stores under
		 * — `self::class`-owned, mirrors the naming
		 * {@see \Woodev_Test_Cdek_Location_Provider}'s own `REGIONS_TRANSIENT_PREFIX`
		 * uses for the same kind of provider-dictionary fact.
		 *
		 * @since 2.1.0
		 * @var string
		 */
		private const REGION_ANCESTOR_CACHE_PREFIX = 'woodev_location_region_ancestor_';

		/**
		 * @since 2.0.2
		 * @var Location_Provider_Registry
		 */
		private Location_Provider_Registry $registry;

		/**
		 * @since 2.0.2
		 * @var Customer_Location_Store
		 */
		private Customer_Location_Store $customer_store;

		/**
		 * @since 2.0.2
		 * @var Location_Resolution_Cache
		 */
		private Location_Resolution_Cache $resolution_cache;

		/**
		 * Memoizes a resolved-but-NOT-persisted default for the rest of THIS
		 * request (review finding F1): a guest REST request has no session yet
		 * until {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}'s
		 * own session bridge runs (WooCommerce does not initialize `WC()->session`
		 * for a plain custom REST route — gotcha `guest-session-write-needs-the-
		 * cart-cookie`), so {@see Customer_Location_Store::set()} can legitimately
		 * return `false` right after {@see self::resolve_default()} succeeded.
		 * Without this, {@see self::get_customer_record()} would (a) lose the
		 * answer it just computed by re-reading a store that never persisted it,
		 * AND (b) re-run {@see self::resolve_default()} — a fresh provider call,
		 * for `geoip` — on every subsequent call within the SAME request.
		 *
		 * Deliberately NOT set when {@see self::resolve_default()} itself
		 * returns `null` (a genuine "no answer" miss, e.g. an unresolvable
		 * geo-IP, or a `fixed` re-resolution that found nothing) — that case
		 * keeps retrying on every call, exactly as before this fix, since there
		 * is nothing to memoize (see `LocationServiceDefaultTest`'s own
		 * "no failure caching" tests, which this must not break).
		 *
		 * @since 2.0.2
		 * @var Location_Record|null
		 */
		private ?Location_Record $unpersisted_default = null;

		/**
		 * Constructor.
		 *
		 * Every collaborator is optional and defaults to the production
		 * instance a caller would otherwise build by hand:
		 * {@see Location_Provider_Registry::instance()} (the shared
		 * fleet-wide singleton Task 3 already owns — this façade does NOT
		 * build its own registry) and fresh {@see Customer_Location_Store} /
		 * {@see Location_Resolution_Cache} instances (both already expose
		 * their own `protected function session()` test seam; this façade
		 * neither wraps nor duplicates it, it simply hands the real
		 * collaborator to a caller — or a test's probe subclass — as-is).
		 * Tests inject fakes/probes for the latter two exactly as
		 * `CustomerLocationStoreTest` and `LocationResolutionCacheTest`
		 * already do, with the real registry singleton reset via
		 * {@see Location_Provider_Registry::reset_for_tests()}.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider_Registry|null $registry         Provider registry; defaults to the shared singleton.
		 * @param Customer_Location_Store|null    $customer_store   Customer location store; defaults to a fresh instance.
		 * @param Location_Resolution_Cache|null  $resolution_cache Adapter resolution cache; defaults to a fresh instance.
		 */
		public function __construct(
			?Location_Provider_Registry $registry = null,
			?Customer_Location_Store $customer_store = null,
			?Location_Resolution_Cache $resolution_cache = null
		) {
			$this->registry         = $registry ?? Location_Provider_Registry::instance();
			$this->customer_store   = $customer_store ?? new Customer_Location_Store();
			$this->resolution_cache = $resolution_cache ?? new Location_Resolution_Cache();
		}

		/**
		 * Whether the layer is fully usable right now: the activation gate is
		 * open (Task 3), an active provider resolves, AND that provider
		 * reports itself configured ({@see Location_Provider::is_configured()},
		 * Task 6/7 contract).
		 *
		 * This is the STRONGER question {@see Location_Provider_Registry::is_needed()}'s
		 * own docblock explicitly defers to this method: the registry only
		 * answers "did a plugin declare need", not "is there actually a
		 * working provider behind that declaration" — e.g. the gate is open,
		 * a provider is active, but it has no API token configured yet.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_active(): bool {
			if ( ! $this->registry->is_needed() ) {
				return false;
			}

			$provider = $this->registry->get_active_provider();

			return null !== $provider && $provider->is_configured();
		}

		/**
		 * Gets the customer's current location record, WITH the `implicit`
		 * flag and the write timestamp — the full shape
		 * {@see Customer_Location_Store::get()} already returns, passed
		 * through unchanged rather than narrowed to a bare record: a caller
		 * needs the flag too (e.g. to decide whether to still show a "please
		 * choose your locality" prompt, spec §4.6 — an implicit default must
		 * never look like a real customer answer).
		 *
		 * This is the LAZY TRIGGER for the store-level default-locality policy
		 * (Task 14; spec D11, §4.6): when the customer has NO record at all
		 * yet, {@see self::resolve_default()} is consulted and, when it
		 * answers a record, written back through {@see Customer_Location_Store::set()}
		 * flagged `implicit` — so every caller of this method (cart shipping
		 * calculation, checkout render) gets the default with zero extra
		 * wiring, and a policy that resolves nothing (`off`, or a `geoip`/
		 * `fixed` miss) costs nothing beyond the one resolution attempt. Once
		 * a record exists — real or implicit — this method never resolves a
		 * default again for it: the early return above short-circuits before
		 * {@see self::resolve_default()} is ever reached, which is also what
		 * keeps this NOT running on every request (a second call within the
		 * same request, or a later request once the write above landed, finds
		 * `$current` non-null and returns immediately).
		 *
		 * A STORED record no longer counts as "having one" once
		 * {@see self::gated_current_entry()} drops it as STALE (#346/#333) —
		 * see that method and {@see self::is_customer_record_stale()} for the
		 * two staleness rules. A stale-only store therefore behaves EXACTLY
		 * like an empty one: the lazy default trigger below runs for it too,
		 * on every read, since nothing is ever written back for a stale
		 * record (the blob itself is left on disk — see
		 * {@see self::gate_chain()}'s own docblock).
		 *
		 * A FRESHLY-RESOLVED default is gated too, BEFORE it is ever served or
		 * persisted (adversarial review finding, s78 — FIX 1): every return
		 * point of this method, including the default-resolution branches
		 * below, now answers a GATED view or `null`, never a raw one, so this
		 * accessor can never disagree with {@see self::get_customer_chain()}
		 * about whether the customer has a location. Before this fix,
		 * `resolve_default()` resolved against the store-setting/`RU` floor
		 * ({@see self::resolve_default_country()}) while the gate's rule (b)
		 * compares against the LIVE {@see self::customer_shipping_country()};
		 * a customer who dropped an old `RU` record after switching to `BY`
		 * would resolve a fresh `RU` default, the re-read gate would reject
		 * it as stale, and the ungated fallback served it anyway — a record
		 * `get_customer_record()` handed out while `get_customer_chain()`
		 * answered `null` for the exact same state. The chosen fix is to gate
		 * the default rather than to make `resolve_default()` country-aware:
		 * a `fixed` default is a merchant-picked, specific locality whose
		 * country cannot be bent to match an arbitrary customer without
		 * picking a DIFFERENT locality (defeating what `fixed` means), and a
		 * `geoip` default's country comes from the IP, not from the checkout
		 * field — neither can honestly be re-targeted at the gate's country
		 * authority. Gating is also simpler: it reuses the exact rule already
		 * trusted for stored records instead of inventing a second country
		 * chain for resolution alone. When a resolved default cannot pass
		 * its own gate, this method answers `null` — precisely what
		 * {@see self::get_customer_chain()} answers for the same state, per
		 * this method's contract: "customer has no location" — rather than
		 * silently degrading into a location the gate itself would refuse
		 * to serve back.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the lazy default-locality resolution trigger
		 *              (Task 14; spec D11).
		 * @since 2.0.2 Memoizes a resolved-but-unpersisted default for the rest
		 *              of the request, and serves it directly rather than
		 *              re-reading a store that failed to write it (review
		 *              finding F1 — {@see self::$unpersisted_default}).
		 * @since 2.0.2 Routes the stored chain through {@see self::gated_current_entry()}
		 *              rather than reading {@see Customer_Location_Store::get()}
		 *              directly, so a STALE record (#346/#333) is treated as
		 *              absent and the lazy default trigger runs for it exactly
		 *              as it does for an empty store.
		 * @since 2.0.2 Gates a freshly-resolved (or cached-unpersisted) default
		 *              against the SAME staleness rule a stored record must
		 *              pass, answering `null` rather than an ungated record
		 *              when it fails (adversarial review finding, s78 — see
		 *              above).
		 * @since 2.0.2 Added the optional `$for_country` parameter, threaded
		 *              through to the gate (#350/#352 follow-up).
		 *
		 * @param string|null $for_country Optional ISO-3166 alpha-2 country code — see {@see self::is_customer_record_stale()}.
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		public function get_customer_record( ?string $for_country = null ): ?array {
			$current = $this->gated_current_entry( $for_country );

			if ( null !== $current ) {
				return $current;
			}

			if ( null !== $this->unpersisted_default ) {
				return $this->is_customer_record_stale( $this->unpersisted_default, $for_country )
					? null
					: self::implicit_entry( $this->unpersisted_default );
			}

			$default = $this->resolve_default();

			if ( null === $default ) {
				return null;
			}

			if ( $this->is_customer_record_stale( $default, $for_country ) ) {
				// The resolved default cannot pass the SAME gate a stored
				// record would have to pass (#346/#333) — see this method's
				// own docblock (FIX 1) for why serving it anyway would
				// disagree with get_customer_chain(). Neither persisted nor
				// cached: a later call in the same request re-resolves it
				// exactly as if nothing had ever resolved.
				return null;
			}

			if ( $this->customer_store->set( $default, true ) ) {
				// Re-read through the SAME gate: a default that just passed
				// the pre-check above is expected to survive it (nothing
				// about persisting it changes its provider or country), but
				// re-gating here rather than trusting that keeps this
				// accessor's contract uniform — every return point answers a
				// gated view, never a raw one. Falls back to the in-memory
				// entry on the theoretical chance it does not (this is now
				// truly theoretical: the pre-check above already validated
				// it against the same rule).
				return $this->gated_current_entry( $for_country ) ?? self::implicit_entry( $default );
			}

			// The write failed (no session yet on this guest REST request — see
			// self::$unpersisted_default). The resolution itself still
			// succeeded and already passed the gate above: serve it for THIS
			// call, and remember it so a later call in the SAME request
			// neither loses it nor re-triggers resolve_default() a second
			// time.
			$this->unpersisted_default = $default;

			return self::implicit_entry( $default );
		}

		/**
		 * Builds the {@see self::get_customer_record()} response shape for a
		 * resolved default that {@see Customer_Location_Store::set()} could not
		 * (yet) persist — see {@see self::$unpersisted_default}.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The resolved default.
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}
		 */
		private static function implicit_entry( Location_Record $record ): array {
			return [
				'record'   => $record,
				'implicit' => true,
				'saved_at' => time(),
			];
		}

		/**
		 * Gets the customer's whole location CHAIN (location-chain design §3:
		 * `docs-internal/specs/2026-08-15-location-chain-design.md`) — every
		 * level the customer has picked that {@see Customer_Location_Store::set()}
		 * has not since dropped AND that still passes the staleness gate
		 * (#346/#333, {@see self::gate_chain()}), plus which level is
		 * `current`.
		 *
		 * Routed through {@see self::get_customer_record()} FIRST, same as
		 * {@see self::get_customer_record_at()} — never
		 * {@see Customer_Location_Store::get_chain()} directly — so the lazy
		 * default-locality trigger (spec D11, §4.6) stays in that ONE place: a
		 * caller reaching for the chain before any record exists yet (or
		 * before anything SURVIVING one exists — a fully-stale chain reads
		 * exactly like an empty one) still gets the store seeded exactly as a
		 * caller reaching for {@see self::get_customer_record()} would.
		 *
		 * The chain itself is then re-read and gated a second time (rather
		 * than reused from that first call) — not free, but not I/O either:
		 * {@see self::gate_chain()} performs no I/O of its own, but a normal
		 * `/suggest` call with a full 3-level chain still costs roughly seven
		 * ownership walks ({@see self::provider_for_level()}, once per
		 * surviving record in EACH of the two gate passes) plus provider and
		 * customer-country resolution before any actual provider work runs —
		 * cheap relative to a network call, not free. Re-gating here (rather
		 * than reusing `get_customer_record()`'s bare entry) keeps this method
		 * correct even though it cannot simply reuse that entry: THIS method
		 * needs every surviving record, not only the one at `current`.
		 *
		 * Also covers the {@see self::$unpersisted_default} gap (review finding
		 * F1): when {@see self::get_customer_record()} just resolved a default
		 * that {@see Customer_Location_Store::set()} could not (yet) persist — a
		 * guest REST request whose session bridge has not run yet — the STORE's
		 * own {@see Customer_Location_Store::get_chain()} legitimately still
		 * answers `null` (nothing landed there), even though
		 * {@see self::get_customer_record()} just answered a record. Without
		 * this, a caller of THIS method would see nothing for the exact same
		 * customer a sibling call to {@see self::get_customer_record()} just
		 * served an answer to — two accessors of the same in-request state
		 * disagreeing. Handled by building a synthetic one-entry chain (the
		 * resolved default at its own level) directly from what
		 * {@see self::get_customer_record()} already returned, rather than
		 * re-deriving it.
		 *
		 * BOTH return shapes are passed through {@see self::with_derived_region_ancestor()}
		 * (issue #551) before this method ever answers — a settlement-only
		 * chain (persisted OR this synthetic one) scopes nothing for its own
		 * search until a region entry exists alongside it, and the store-level
		 * default-locality policy only ever seeds the SETTLEMENT it was
		 * configured with, never a region. Applying the same enrichment to
		 * both shapes is deliberate, not incidental: they must never disagree
		 * about whether a region is derivable for the same underlying record.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Gates the stored chain against staleness (#346/#333,
		 *              {@see self::gate_chain()}) rather than returning
		 *              {@see Customer_Location_Store::get_chain()}'s raw
		 *              answer.
		 * @since 2.0.2 Added the optional `$for_country` parameter, threaded
		 *              through to {@see self::get_customer_record()} and
		 *              {@see self::gate_chain()} (#350/#352 follow-up).
		 * @since 2.1.0 Fills a missing REGION ancestor from the settlement
		 *              record's own published ancestors, on both return shapes
		 *              (issue #551), via {@see self::with_derived_region_ancestor()}.
		 *
		 * @param string|null $for_country Optional ISO-3166 alpha-2 country code — see {@see self::is_customer_record_stale()}.
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null
		 */
		public function get_customer_chain( ?string $for_country = null ): ?array {
			$current = $this->get_customer_record( $for_country );

			if ( null === $current ) {
				return null;
			}

			$raw   = $this->customer_store->get_chain();
			$gated = null === $raw ? null : $this->gate_chain( $raw, $for_country );

			if ( null !== $gated ) {
				return $this->with_derived_region_ancestor( $gated );
			}

			// self::$unpersisted_default (review finding F1, see docblock above):
			// the store has nothing persisted yet, but get_customer_record() just
			// resolved (and could not write) a default. Build the one-entry chain
			// that write WOULD have produced, so this accessor agrees with it.
			return $this->with_derived_region_ancestor(
				[
					'records'  => [ $current['record']->level() => $current['record'] ],
					'current'  => $current['record']->level(),
					'implicit' => $current['implicit'],
					'saved_at' => $current['saved_at'],
				]
			);
		}

		/**
		 * Fills a chain's missing REGION ancestor from its SETTLEMENT record's
		 * own published `ancestors()` (issue #551 — the sibling gap #538 left
		 * open: that fix narrows the POPULAR list via ancestor INTERSECTION,
		 * answerable from the flat set alone, but scoping a provider `/suggest`
		 * call needs one specific region KEY, which the flat set — deliberately,
		 * see {@see Location_Record::parse_ancestors()}'s own docblock — cannot
		 * name by itself; only asking the record's own provider, via
		 * {@see self::region_ancestor_of()}, can answer which ancestor IS the
		 * region).
		 *
		 * NEVER overrides an already-present `records['region']` — a customer's
		 * own region pick always wins, and this can never disturb it. A no-op
		 * whenever there is no settlement to derive from either.
		 *
		 * Additive only: `current` is left exactly as given — a derived region
		 * is shallower than the settlement it came from, so it can never BE
		 * `current` — and every other key on `$chain` passes through unchanged.
		 *
		 * @since 2.1.0
		 *
		 * @param array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int} $chain
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}
		 */
		private function with_derived_region_ancestor( array $chain ): array {
			if ( isset( $chain['records'][ Location_Record::LEVEL_REGION ] ) || ! isset( $chain['records'][ Location_Record::LEVEL_SETTLEMENT ] ) ) {
				return $chain;
			}

			$region = $this->region_ancestor_of( $chain['records'][ Location_Record::LEVEL_SETTLEMENT ] );

			if ( null !== $region ) {
				$chain['records'][ Location_Record::LEVEL_REGION ] = $region;
			}

			return $chain;
		}

		/**
		 * Derives `$settlement`'s REGION-level ancestor, if its own provider can
		 * identify one (issue #551).
		 *
		 * Asks the SAME provider that produced `$settlement` — never
		 * {@see self::provider_for_level()}, which could resolve to a DIFFERENT
		 * provider in a mixed D15 chain — because an ancestor key is namespaced
		 * to whichever provider published it
		 * ({@see Location_Record::parse_ancestors()}'s own enforcement), so only
		 * that exact provider can ever resolve it back.
		 *
		 * Gated on {@see Location_Provider::CAPABILITY_RESOLVE_KEY} — considered
		 * against {@see Location_Provider::CAPABILITY_LIST} and rejected: DaData
		 * structurally cannot enumerate (query-driven API only) and so never
		 * declares `CAPABILITY_LIST` at all, which would leave every DaData-only
		 * store with no fix; `CAPABILITY_RESOLVE_KEY` is the one both bundled
		 * providers can declare, and resolving one specific key is also far
		 * cheaper than pulling a whole country's region dictionary just to test
		 * membership of one or two keys.
		 *
		 * Tries each published ancestor in turn — {@see Location_Record::parse_ancestors()}'s
		 * flat SET can carry more than one (e.g. a district alongside a region)
		 * — and returns the FIRST one {@see Location_Provider::resolve_key()}
		 * confirms is itself region-level. `null` when the provider is not
		 * registered, lacks the capability, `$settlement` publishes no
		 * ancestors, or none of them resolve to a region — every provider-side
		 * outcome (an unknown key, a non-region level, or an actual failure)
		 * degrades to "no region" here; see {@see self::cached_region_lookup()}
		 * for why a failure specifically is never cached.
		 *
		 * @since 2.1.0
		 *
		 * @param Location_Record $settlement
		 *
		 * @return Location_Record|null
		 */
		private function region_ancestor_of( Location_Record $settlement ): ?Location_Record {
			$ancestors = $settlement->ancestors();

			if ( [] === $ancestors ) {
				return null;
			}

			$provider = $this->get_registered_provider( $settlement->provider_id() );

			if ( null === $provider || ! in_array( Location_Provider::CAPABILITY_RESOLVE_KEY, $provider->get_capabilities(), true ) ) {
				return null;
			}

			foreach ( $ancestors as $ancestor_key ) {
				$region = $this->cached_region_lookup( $provider, $ancestor_key );

				if ( null !== $region ) {
					return $region;
				}
			}

			return null;
		}

		/**
		 * Resolves `$key` through `$provider` and answers it back ONLY when it
		 * is itself region-level — cached in a SITE-WIDE transient (issue #551),
		 * never per-customer/session: which ancestor key is a region is a fact
		 * about the PROVIDER's own dictionary, identical for every customer, so
		 * a session-scoped cache (the shape {@see Location_Resolution_Cache}
		 * uses for its own, genuinely per-plugin-per-customer answer) would
		 * needlessly re-ask the provider once per new guest session.
		 *
		 * A cache is required here, not optional: {@see Location_Provider::resolve_key()}'s
		 * own contract is explicitly "re-fetched, not cached" (that method's own
		 * docblock), and this is called from the lazy default-locality trigger
		 * that every guest checkout render (and every `/suggest`/`/select` call)
		 * routes through — an uncached call here would mean a live provider
		 * request on every single page load.
		 *
		 * A THROW from `resolve_key()` (unconfigured, a transport failure, a
		 * malformed payload — see that method's own docblock) is NEVER cached,
		 * mirroring {@see Popular_Settlement_Verifier::verify_entry()}'s own
		 * discipline for this exact same call: a transient failure must retry
		 * on the very next request, not calcify into "no region" for a whole
		 * cache lifetime.
		 *
		 * @since 2.1.0
		 *
		 * @param Location_Provider $provider The provider that owns `$key`.
		 * @param string            $key      A locality key `$provider` previously produced.
		 *
		 * @return Location_Record|null
		 */
		private function cached_region_lookup( Location_Provider $provider, string $key ): ?Location_Record {
			$transient_key = self::REGION_ANCESTOR_CACHE_PREFIX . md5( $key );
			$cached        = get_transient( $transient_key );

			if ( is_array( $cached ) ) {
				return [] === $cached ? null : Location_Record::from_array( $cached );
			}

			try {
				$resolved = $provider->resolve_key( $key );
			} catch ( \Throwable $exception ) {
				return null; // Never cached — see this method's own docblock.
			}

			$is_region = null !== $resolved && Location_Record::LEVEL_REGION === $resolved->level();

			/**
			 * Filters {@see Location_Service::cached_region_lookup()}'s cache TTL,
			 * in seconds (issue #551) — same shape as
			 * {@see Location_Resolution_Cache::FILTER_TTL}.
			 *
			 * @since 2.1.0
			 *
			 * @param int $ttl Seconds; `DAY_IN_SECONDS` default — a provider's
			 *                 region dictionary changes on the order of years,
			 *                 not requests (matching the bundled test-rig CDEK
			 *                 fixture's own internal dictionary cache TTL).
			 */
			$ttl = (int) apply_filters( self::FILTER_REGION_ANCESTOR_CACHE_TTL, DAY_IN_SECONDS );

			set_transient( $transient_key, $is_region ? $resolved->to_array() : [], max( 0, $ttl ) );

			return $is_region ? $resolved : null;
		}

		/**
		 * Gets the customer's record AT a specific chain level — e.g. the
		 * settlement the customer actually picked, even when an address is
		 * `current` (#334: `Provider_Selection_Scope::current_locality()` is
		 * built on top of exactly this, spec §5).
		 *
		 * Routed through {@see self::get_customer_record()} first (via
		 * {@see self::get_customer_chain()}) for the same lazy-default-trigger
		 * reason documented there.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$for_country` parameter, threaded
		 *              through to {@see self::get_customer_chain()} (#350/#352
		 *              follow-up).
		 *
		 * @param string      $level       One of {@see Location_Record::LEVELS}.
		 * @param string|null $for_country Optional ISO-3166 alpha-2 country code — see {@see self::is_customer_record_stale()}.
		 *
		 * @return Location_Record|null `null` for an unknown level string, or a
		 *                               level the customer has not (yet) picked.
		 */
		public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
			$chain = $this->get_customer_chain( $for_country );

			if ( null === $chain || ! isset( $chain['records'][ $level ] ) ) {
				return null;
			}

			return $chain['records'][ $level ];
		}

		/**
		 * Reads the customer's stored chain and gates it against staleness in
		 * one step, projected down to {@see Customer_Location_Store::get()}'s
		 * own entry shape (the record AT the gated chain's own `current`
		 * level) — the single read path both {@see self::get_customer_record()}
		 * and its post-write re-read share.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$for_country` parameter, threaded
		 *              through to {@see self::gate_chain()} (#350/#352
		 *              follow-up).
		 *
		 * @param string|null $for_country Optional ISO-3166 alpha-2 country code — see {@see self::is_customer_record_stale()}.
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null `null` when nothing is stored, or nothing SURVIVES the gate.
		 */
		private function gated_current_entry( ?string $for_country = null ): ?array {
			$chain = $this->customer_store->get_chain();

			if ( null === $chain ) {
				return null;
			}

			$gated = $this->gate_chain( $chain, $for_country );

			if ( null === $gated ) {
				return null;
			}

			return [
				'record'   => $gated['records'][ $gated['current'] ],
				'implicit' => $gated['implicit'],
				'saved_at' => $gated['saved_at'],
			];
		}

		/**
		 * Filters a raw stored chain down to the records that are still VALID
		 * to serve on THIS read (#346/#333) — a record whose owning provider or
		 * country has moved out from under it since it was written is STALE,
		 * and a stale record is treated as ABSENT, never re-resolved (operator
		 * decision: "считать отсутствующей"). See
		 * {@see self::is_customer_record_stale()} for the two staleness rules
		 * this applies per record.
		 *
		 * Gated in CASCADE ORDER ({@see Location_Record::LEVELS}), not
		 * independently per level (adversarial review finding, s78): once a
		 * level is dropped as stale, every DEEPER level is dropped too, even
		 * one that would individually still pass. A surviving record must
		 * have unbroken surviving ancestry — this mirrors
		 * {@see Customer_Location_Store::rebuild_chain()}'s own invariant
		 * (an ancestor only enters a rebuilt chain when the new record is
		 * actually `is_within()` it), which a chain gated level-by-level
		 * could otherwise violate: dropping a stale `settlement` while
		 * keeping a surviving `address` would hand back `{address: …}` with
		 * `current = address` — an address with no settlement above it,
		 * a shape `rebuild_chain()` would never itself produce and the
		 * cascade model `region > settlement > address` does not permit.
		 *
		 * The gate re-applies on EVERY read and never writes anything back —
		 * the stored blob is left exactly as it is, staleness or not. A real
		 * "forget" path for a permanently-stale blob (so it stops being
		 * re-evaluated, and re-served as `implicit` history, on every future
		 * request) is still missing; tracked separately as #356 rather than
		 * folded into this read-side fix, which the operator scoped to
		 * READING only ("Плюс почини сигнал" — the second half of that same
		 * decision is Part 2 of this change, not a write).
		 *
		 * `current` is recomputed to the DEEPEST surviving level
		 * ({@see Location_Record::LEVELS} cascade order) rather than trusting
		 * the stored `current` pointer — a record dropped out from under that
		 * pointer must not leave the gated chain pointing at a level no longer
		 * present. This mirrors {@see Customer_Location_Store::rebuild_chain()}'s
		 * own invariant that `current` always names the deepest record in the
		 * chain.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Gates in cascade order and drops every descendant of a
		 *              dropped ancestor, rather than filtering each level
		 *              independently (adversarial review finding, s78) — see
		 *              above.
		 * @since 2.0.2 Added the optional `$for_country` parameter, threaded
		 *              through to {@see self::is_customer_record_stale()}
		 *              (#350/#352 follow-up).
		 *
		 * @param array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int} $chain       Raw stored chain.
		 * @param string|null                                                                                    $for_country Optional ISO-3166 alpha-2 country code — see {@see self::is_customer_record_stale()}.
		 *
		 * @return array{records: array<string, Location_Record>, current: string, implicit: bool, saved_at: int}|null `null` when NOTHING survives — the caller must then treat this exactly like an empty store.
		 */
		private function gate_chain( array $chain, ?string $for_country = null ): ?array {
			$records = [];
			$broken  = false;

			foreach ( Location_Record::LEVELS as $level ) {
				if ( ! isset( $chain['records'][ $level ] ) ) {
					continue;
				}

				if ( $broken || $this->is_customer_record_stale( $chain['records'][ $level ], $for_country ) ) {
					// Once an ancestor is stale, every deeper level is stale
					// too by construction — its ancestry is broken even if
					// it would individually still pass the gate.
					$broken = true;
					continue;
				}

				$records[ $level ] = $chain['records'][ $level ];
			}

			if ( [] === $records ) {
				return null;
			}

			$current = $chain['current'];

			if ( ! isset( $records[ $current ] ) ) {
				foreach ( array_reverse( Location_Record::LEVELS ) as $level ) {
					if ( isset( $records[ $level ] ) ) {
						$current = $level;
						break;
					}
				}
			}

			return [
				'records'  => $records,
				'current'  => $current,
				'implicit' => $chain['implicit'],
				'saved_at' => $chain['saved_at'],
			];
		}

		/**
		 * Whether a stored customer record is STALE (#346/#333) — must be read
		 * as ABSENT by {@see self::gate_chain()} even though it is still on
		 * disk. Either rule below is sufficient on its own.
		 *
		 * (a) PROVIDER OWNERSHIP moved (#333): {@see self::provider_for_level()}
		 * — the same D15 chain walk (active provider -> bundled fallback ->
		 * nobody) every OTHER read of "who serves this level" already goes
		 * through — no longer resolves to the SAME provider that produced this
		 * record. This is deliberately NOT "the shop's active provider
		 * changed": `provider_for_level()` itself falls back to the bundled
		 * provider when the active one does not serve the level, so a record
		 * the BUNDLED provider produced stays valid for as long as the bundled
		 * provider remains the one the D15 chain actually resolves for that
		 * level — only a change in WHICH provider is the resolved OWNER drops
		 * it. A level nobody serves at all (`provider_for_level()` -> `null`)
		 * is stale too — there is no owner left to vouch for it.
		 *
		 * (b) COUNTRY moved (#346): the record's own {@see Location_Record::country()}
		 * no longer matches the country of authority for this read, as
		 * `$for_country` (when given) or {@see self::customer_shipping_country()}
		 * resolves it — switching the checkout country used to republish the
		 * stale record with nothing catching it. There is deliberately no
		 * "unknown country" escape here (operator correction, s79):
		 * {@see self::customer_shipping_country()} ALWAYS answers something —
		 * the same checkout-field -> store-setting -> `RU` floor
		 * {@see self::resolve_default_country()} already guarantees — so an
		 * "undeterminable" state this rule would need to special-case simply
		 * does not exist, and a caller-supplied `$for_country` (already
		 * normalized ISO-3166 by the caller — see `$for_country` below) is
		 * never empty either.
		 *
		 * `$for_country` (optional, #350/#352 follow-up — call-site-aware
		 * country authority): when given, rule (b) compares against IT
		 * instead of the ambient {@see self::customer_shipping_country()}.
		 * A REST read that already carries its own normalized `country`
		 * request param (`/suggest`, `/list` — see
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::build_scope()})
		 * is a STRONGER authority for that one request than WooCommerce's
		 * ambient customer object, which can disagree with it (a fresh
		 * guest's shipping country resolves through
		 * `wc_get_customer_default_location()`'s geolocation fallback, which
		 * lands on a hardcoded `US` for a non-routable IP — gotcha
		 * `wc-customer-default-location-geolocation-fallback` — entirely
		 * independent of what country the CURRENT request is actually
		 * asking about). Omitted (`null`, the default): behaves EXACTLY as
		 * before this parameter was added — the checkout-render and pickup
		 * paths, where the ambient WooCommerce customer IS the right
		 * authority, are unaffected.
		 *
		 * `protected` (not `private`) as a test seam, same discipline as
		 * {@see self::customer_shipping_country()} right below it: a test file
		 * whose fixtures are not ABOUT staleness at all (e.g. `PickupHandlerTest`,
		 * `ProviderSelectionScopeTest`) can override this to bypass the D15
		 * provider-ownership/country machinery entirely rather than being forced
		 * to open the real provider registry gate for every fixture record it
		 * builds.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$for_country` parameter (#350/#352
		 *              follow-up) so a call-site with its own stronger country
		 *              authority (a REST request's own `country` param) is no
		 *              longer forced through the ambient customer object.
		 *
		 * @param Location_Record $record      Stored record to check.
		 * @param string|null     $for_country Optional ISO-3166 alpha-2 country
		 *                                     code to check rule (b) against
		 *                                     instead of the ambient
		 *                                     {@see self::customer_shipping_country()}.
		 *
		 * @return bool
		 */
		protected function is_customer_record_stale( Location_Record $record, ?string $for_country = null ): bool {
			$owner = $this->provider_for_level( $record->level(), $record->country() );

			if ( null === $owner || $owner->get_id() !== $record->provider_id() ) {
				return true;
			}

			$country = $for_country ?? $this->customer_shipping_country();

			return $country !== $record->country();
		}

		/**
		 * The customer's shipping country — the authority
		 * {@see self::is_customer_record_stale()}'s rule (b) checks a stored
		 * record's own country against (#346). NEVER empty (operator
		 * correction, s79): reuses the SAME chain
		 * {@see self::resolve_default_country()} already embodies (checkout
		 * field -> WooCommerce store setting -> `RU`) rather than inventing a
		 * second, subtly different one — step 1 (the LIVE shipping field) is
		 * added HERE, on top of it, because `resolve_default_country()`'s own
		 * docblock is explicit that step 1 is each CALLER's job, never that
		 * method's ({@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::perform_suggest()}
		 * reads the REQUEST's own `country` param for step 1; this gate has no
		 * request to read, so it reads WooCommerce's own live session value
		 * for the customer instead — {@see \WC_Customer::get_shipping_country()},
		 * the field the checkout's own `update_checkout` AJAX call keeps
		 * current while the customer types).
		 *
		 * `protected` as a test seam — same shape and reasoning as
		 * {@see Customer_Location_Store::session()} /
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::current_country()}:
		 * a probe overrides this single line rather than needing `WC()` to be a
		 * real function in the unit-test process (Brain Monkey's
		 * Patchwork-based `Functions\when( 'WC' )` redefinition would leak
		 * `function_exists( 'WC' )` as permanently `true` for the rest of that
		 * PHPUnit process). Deliberately reads the SHIPPING field, not billing
		 * — unlike `Checkout_Handler::current_country()`, which reads billing
		 * for ITS OWN, unrelated purpose (enhancing the country field's
		 * initial render).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Dropped the "empty means unknown, survive rule (b)"
		 *              behaviour (operator correction, s79): that state was
		 *              never reachable — {@see self::resolve_default_country()}'s
		 *              own floor guarantees a non-empty answer, so treating an
		 *              empty LIVE field as anything other than "fall through to
		 *              that floor" was untestable dead defensiveness, not a
		 *              real case.
		 *
		 * @return string ISO-3166 alpha-2 country code (upper-case), never
		 *                empty.
		 */
		protected function customer_shipping_country(): string {
			if ( function_exists( 'WC' ) && WC()->customer ) {
				$live = strtoupper( trim( (string) WC()->customer->get_shipping_country() ) );

				if ( '' !== $live ) {
					return $live;
				}
			}

			return $this->resolve_default_country();
		}

		/**
		 * Writes the customer's location record. Thin pass-through to
		 * {@see Customer_Location_Store::set()} — see that method for the
		 * implicit/explicit precedence rule (spec D11) and the guest-session
		 * degradation (returns `false`, never a fatal, when no session is
		 * available to write into).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record   The record to store.
		 * @param bool            $implicit Whether this is a default guess
		 *                                  (spec D11) rather than a real
		 *                                  customer choice.
		 *
		 * @return bool `true` when the write happened.
		 */
		public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
			return $this->customer_store->set( $record, $implicit );
		}

		/**
		 * Resolves the store-level default locality (Task 14; spec D11, §4.6):
		 * `off` -> `null`; `fixed` -> the merchant-picked record (re-resolved
		 * through the current provider first when a provider switch stranded
		 * its namespace — see {@see self::resolve_fixed_default()});
		 * `geoip` -> the active provider's `locate( $ip )` answer for the
		 * customer's IP (via `WC_Geolocation::get_ip_address()`).
		 *
		 * Called ONLY from {@see self::get_customer_record()}'s lazy trigger
		 * when the customer has no record at all yet — never proactively, and
		 * never on a request that already has one. Nothing here is stored;
		 * the caller decides whether/how to persist the result.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		public function resolve_default(): ?Location_Record {
			$policy = $this->registry->get_default_locality_policy();

			if ( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED === $policy ) {
				return $this->resolve_fixed_default();
			}

			if ( Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP === $policy ) {
				return $this->resolve_geoip_default();
			}

			return null;
		}

		/**
		 * Gets the store's default-locality policy (spec D11, §4.6) — thin
		 * pass-through to {@see Location_Provider_Registry::get_default_locality_policy()},
		 * same facade pattern as {@see self::get_field_mode_settlement()}.
		 *
		 * Issue #536: {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}
		 * reads this to decide whether the `defaultLocality` config block is
		 * worth sending at all — a customer-facing config block, so this is a
		 * PURE READ, same discipline as {@see self::resolve_fixed_default()}'s
		 * own "customer-facing getter must never mutate a store setting" rule.
		 *
		 * @since 2.1.0
		 *
		 * @return string One of {@see Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_OFF} /
		 *                {@see Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_FIXED} /
		 *                {@see Location_Provider_Registry::DEFAULT_LOCALITY_POLICY_GEOIP}.
		 */
		public function get_default_locality_policy(): string {
			return $this->registry->get_default_locality_policy();
		}

		/**
		 * Resolves the `fixed` default-locality policy (spec §4.6/D15
		 * amendment): the merchant's stored record is served AS-IS when the
		 * D15 chain still resolves the SAME provider for its level — meaning
		 * its `provider_id` namespace is still valid — otherwise it is
		 * STRANDED (the store switched providers since it was picked) and is
		 * re-resolved by components through whichever provider the chain now
		 * resolves for that level ({@see self::reresolve_fixed_default()}), for
		 * THIS call only.
		 *
		 * PURE READ, no store-settings write (review finding F2): this method
		 * is reached from {@see self::get_customer_record()}'s lazy trigger,
		 * which the public, unauthenticated `/location/suggest` route reaches
		 * for every anonymous guest — a customer-facing getter must never
		 * mutate the merchant's `default_locality_record` /
		 * `default_locality_needs_repick` store settings, or an anonymous
		 * visitor's search-box keystrokes could silently re-point the
		 * merchant's configured default to whatever a re-resolution happens to
		 * match. Earlier revisions REPLACED the stored record here so only the
		 * first customer after a provider switch paid the re-resolution cost;
		 * that "optimization" WAS the vulnerability, so it is gone — every
		 * stranded request now re-computes, but each CUSTOMER still only pays
		 * once, because the computed record is written to THAT customer's own
		 * session/account via {@see self::get_customer_record()}'s caller, not
		 * to the shared merchant setting. Persisting a re-resolved record onto
		 * the merchant's own setting (so later customers skip the
		 * re-computation too) belongs in an authenticated admin action or a
		 * scheduled resync — neither exists yet in this codebase; the settings
		 * page instead surfaces the stranded state live (never from a stored
		 * flag this method would have to keep honest) — see
		 * {@see Location_Provider_Registry::apply_default_locality_status_note()}.
		 *
		 * A failed re-resolution (nothing to re-resolve THROUGH, an ambiguous
		 * or absent match, or the new provider's `suggest()` throwing) treats
		 * the default as unset for this call — a stale foreign-namespace
		 * record is never served either way.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 No longer writes {@see Location_Provider_Registry::set_default_locality_record()}
		 *              / the (issue #406: since removed) needs-repick setter
		 *              — review finding F2: a customer-facing getter must not
		 *              mutate store settings.
		 *
		 * @return Location_Record|null
		 */
		private function resolve_fixed_default(): ?Location_Record {
			$stored = $this->registry->get_default_locality_record();

			if ( null === $stored ) {
				return null;
			}

			$current_provider = $this->provider_for_level( $stored->level() );

			if ( null !== $current_provider && $current_provider->get_id() === $stored->provider_id() ) {
				return $stored;
			}

			return null !== $current_provider ? $this->reresolve_fixed_default( $current_provider, $stored ) : null;
		}

		/**
		 * Re-resolves a stranded `fixed` default THROUGH `$provider` — the D15
		 * chain's current answer for `$stored`'s own level, which is by
		 * construction NOT the provider `$stored->provider_id()` names
		 * (that is exactly what makes it stranded, see
		 * {@see self::resolve_fixed_default()}). Queries `$provider->suggest()`
		 * with `$stored`'s own label (or, failing that, its own level's
		 * component name) scoped to `$stored`'s parent components, and accepts
		 * only an UNAMBIGUOUS match (review finding F2b) — see
		 * {@see self::unambiguous_match()}. Never throws: a provider failure,
		 * an empty/refused query, an unnarrowable scope, or no unambiguous
		 * match at all, all resolve to `null`.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Refuses a settlement/address-level record with no
		 *              parent component to narrow by, rather than silently
		 *              issuing a country-wide search (review finding F2:
		 *              DaData's own constraint builder reads only `region`/
		 *              `settlement` names, so an empty components array yields
		 *              no `locations` filter at all).
		 * @since 2.0.2 Requires {@see self::unambiguous_match()} instead of
		 *              blindly accepting `$records[0]` (review finding F2b).
		 *
		 * @param Location_Provider $provider The provider to re-resolve through.
		 * @param Location_Record   $stored   The stranded stored record.
		 *
		 * @return Location_Record|null
		 */
		private function reresolve_fixed_default( Location_Provider $provider, Location_Record $stored ): ?Location_Record {
			$query = self::query_text_for( $stored );

			if ( '' === $query ) {
				return null;
			}

			if ( self::needs_parent_narrowing( $stored ) && [] === self::parent_components_above( $stored ) ) {
				return null;
			}

			try {
				$records = $provider->suggest( $query, self::scope_for_reresolution( $stored ) );
			} catch ( \Throwable $exception ) {
				return null;
			}

			return self::unambiguous_match( $records, $stored );
		}

		/**
		 * Whether {@see self::reresolve_fixed_default()} must have a non-empty
		 * parent constraint before it may query at all — every level except
		 * `region` (which has no parent to narrow by in the first place; see
		 * {@see self::scope_for_reresolution()}).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $stored The stranded stored record.
		 *
		 * @return bool
		 */
		private static function needs_parent_narrowing( Location_Record $stored ): bool {
			return Location_Record::LEVEL_REGION !== $stored->level();
		}

		/**
		 * Picks the ONE candidate `$records` that unambiguously replaces
		 * `$stored` (review finding F2b): a match at the SAME level carrying
		 * the EXACT SAME display label — accepted only when it is the ONE such
		 * candidate. Zero matches, or more than one (e.g. two same-named
		 * settlements in different regions — the exact "Мирный" scenario the
		 * review measured against DaData), both refuse rather than guess;
		 * `$records[0]` alone was never a safe proxy for "the merchant's own
		 * locality", since the provider is answering a bare label with no
		 * enforceable parent scope on a provider that ignores an empty one.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record[] $records Candidates `$provider->suggest()` returned.
		 * @param Location_Record   $stored  The stranded stored record being replaced.
		 *
		 * @return Location_Record|null
		 */
		private static function unambiguous_match( array $records, Location_Record $stored ): ?Location_Record {
			$matches = array_values(
				array_filter(
					$records,
					static fn( $candidate ): bool => $candidate instanceof Location_Record
						&& $candidate->level() === $stored->level()
						&& $candidate->label() === $stored->label()
				)
			);

			return 1 === count( $matches ) ? $matches[0] : null;
		}

		/**
		 * Builds the lookup scope {@see self::reresolve_fixed_default()} queries
		 * `$stored`'s replacement through — the country/level pair plus,
		 * for a settlement/address-level record, the components ABOVE its own
		 * level (`Location_Scope::within_components()`, built specifically for
		 * this "have components, no record from THIS provider yet" case — see
		 * that method's own docblock). A region-level record has no parent to
		 * constrain by, so it scopes by country alone.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $stored The stranded stored record.
		 *
		 * @return Location_Scope
		 */
		private static function scope_for_reresolution( Location_Record $stored ): Location_Scope {
			if ( Location_Record::LEVEL_REGION === $stored->level() ) {
				return Location_Scope::for_country( $stored->country(), $stored->level() );
			}

			return Location_Scope::within_components( $stored->country(), $stored->level(), self::parent_components_above( $stored ) );
		}

		/**
		 * Extracts the component groups ABOVE `$record`'s own level — region
		 * (+district) for a settlement-level record, region/district/settlement
		 * for an address-level one — in the shape
		 * {@see Location_Scope::within_components()} expects. A region-level
		 * record has nothing above it, so this returns `[]` for one (never
		 * called for that level anyway — see {@see self::scope_for_reresolution()}).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The record to extract parent components from.
		 *
		 * @return array<string, mixed>
		 */
		private static function parent_components_above( Location_Record $record ): array {
			$components = [];

			if ( Location_Record::LEVEL_REGION === $record->level() ) {
				return $components;
			}

			if ( null !== $record->region() ) {
				$components['region'] = $record->region();
			}

			if ( null !== $record->district() ) {
				$components['district'] = $record->district();
			}

			if ( Location_Record::LEVEL_ADDRESS === $record->level() && null !== $record->settlement() ) {
				$components['settlement'] = $record->settlement();
			}

			return $components;
		}

		/**
		 * The free-text query {@see self::reresolve_fixed_default()} searches
		 * the new provider with: `$record`'s own display label when it has
		 * one, otherwise the name of its own level's component group
		 * (region/settlement/street), otherwise `''` — an empty query is the
		 * caller's signal to give up rather than issue a call no provider
		 * could usefully answer.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record The record to derive a query from.
		 *
		 * @return string
		 */
		private static function query_text_for( Location_Record $record ): string {
			if ( '' !== $record->label() ) {
				return $record->label();
			}

			switch ( $record->level() ) {
				case Location_Record::LEVEL_REGION:
					$component = $record->region();
					break;

				case Location_Record::LEVEL_SETTLEMENT:
					$component = $record->settlement();
					break;

				default:
					$component = $record->street();
					break;
			}

			return null !== $component ? (string) $component['name'] : '';
		}

		/**
		 * Resolves the `geoip` default-locality policy (spec D11): the
		 * customer's own IP (`WC_Geolocation::get_ip_address()`, mirroring the
		 * trusted-IP discipline {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}'s
		 * own rate-limit trait already uses) run through {@see self::locate()}.
		 * An unresolvable IP resolves to `null` the same way every other
		 * `locate()` miss does — see that method's own docblock.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		private function resolve_geoip_default(): ?Location_Record {
			if ( ! class_exists( '\\WC_Geolocation' ) ) {
				return null;
			}

			$ip = (string) \WC_Geolocation::get_ip_address();

			if ( '' === $ip ) {
				return null;
			}

			return $this->locate( $ip );
		}

		/**
		 * Resolves a geo-IP lookup through the active provider's `locate()`
		 * capability, when it declares one. Shared by {@see self::resolve_geoip_default()}
		 * (the `geoip` default-locality policy) and the admin-only
		 * default-locality picker's own "locate" action
		 * ({@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_admin_locate_request()}) —
		 * both need the exact same "does the active provider even support
		 * this, and if so what does it say for this IP" answer.
		 *
		 * No `locate` capability ({@see self::supports_locate()}), a
		 * `locate()` miss (`null`), or a thrown failure all resolve to `null`
		 * alike — geo-IP is a legitimate, non-exceptional "no answer" outcome
		 * (see {@see Location_Provider::locate()}'s own docblock), never
		 * cached as a failure by this method (a caller that wants
		 * retry-avoidance implements its own, as {@see self::resolve_geoip_default()}'s
		 * own docblock notes none is needed for that call site).
		 *
		 * @since 2.0.2
		 *
		 * @param string $ip IPv4 or IPv6 address.
		 *
		 * @return Location_Record|null
		 */
		public function locate( string $ip ): ?Location_Record {
			if ( ! $this->supports_locate() ) {
				return null;
			}

			try {
				return $this->registry->get_active_provider()->locate( $ip );
			} catch ( \Throwable $exception ) {
				return null;
			}
		}

		/**
		 * Whether the active provider currently declares the `locate`
		 * capability — the same "is there anything to even ask" check
		 * {@see self::locate()} makes internally, exposed separately so a
		 * caller (the admin-only `/default-locality/locate` route) can tell
		 * "this capability does not exist right now" (a 404-worthy, "this
		 * stopped being true" condition) apart from "it exists but this
		 * particular IP resolved to nothing" (a legitimate 200 + null).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function supports_locate(): bool {
			$provider = $this->registry->get_active_provider();

			return null !== $provider && in_array( Location_Provider::CAPABILITY_LOCATE, $provider->get_capabilities(), true );
		}

		/**
		 * Resolves `$plugin`'s carrier identity for a location record — cached
		 * (Task 5, spec D9).
		 *
		 * `$record` defaults to `null`, which resolves for the customer's
		 * CURRENT record, exactly as before this parameter existed — every
		 * pre-existing caller is unaffected. Pass an explicit `$record` to
		 * resolve for a DIFFERENT record the caller has already chosen to
		 * address by (issue #336: {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::location_context()}
		 * passes the settlement-preferred record
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_location_record()}
		 * resolved, so the adapter resolves the carrier city for the SAME
		 * record the pickup map is addressed by, not a re-derived current one).
		 *
		 * Returns `null` in two situations a caller cannot tell apart from the
		 * return value alone: `$record` is `null` (explicit or defaulted) and
		 * the customer has no CURRENT record at all yet (this method never even
		 * reaches the cache/adapter in that case — see
		 * {@see self::get_customer_record()} first if the distinction
		 * matters), or the carrier genuinely does not serve the given
		 * locality (a legitimate, cached answer from
		 * {@see Location_Resolution_Cache::resolve_for()} itself).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$record` parameter (issue #336). The
		 *              default (`null`) is unchanged — do NOT flip it; this
		 *              method has other callers that must keep resolving
		 *              against the customer's current record.
		 *
		 * @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin The plugin
		 *        whose adapter should resolve the given locality.
		 * @param Location_Record|null                       $record The record
		 *        to resolve for. `null` (default) resolves for the customer's
		 *        current record.
		 *
		 * @return mixed|null The plugin's carrier identity (opaque to the
		 *                     framework), or `null` (no record yet, or the
		 *                     carrier does not serve this locality).
		 *
		 * @throws \Throwable Re-thrown, after logging, when the adapter itself
		 *                     threw — see {@see Location_Resolution_Cache::resolve_for()}.
		 */
		public function resolve_for( \Woodev\Framework\Shipping\Shipping_Plugin $plugin, ?Location_Record $record = null ) {
			if ( null === $record ) {
				$current = $this->get_customer_record();

				if ( null === $current ) {
					return null;
				}

				$record = $current['record'];
			}

			return $this->resolution_cache->resolve_for( $plugin, $record );
		}

		/**
		 * Resolves the store-wide fallback country for a checkout that has NO country field at
		 * all (issue #296) — steps 2+3 of the chain `checkout field -> WooCommerce store setting
		 * -> RU`; step 1 (the live checkout field itself) is read by each CALLER, never here:
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::perform_suggest()} reads
		 * the request's own `country` param first and only reaches this method when that is
		 * empty, and `location-cascade.js`'s own `countryFor()` reads the live DOM field first
		 * and only falls back to the `defaultCountry` this method feeds into
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}.
		 * BOTH call sites resolve through this ONE method for the ONE reason this project has
		 * already paid for once: a seam where each side of a client/server boundary shipped its
		 * own, independently-green implementation that quietly disagreed.
		 *
		 * Step 2 reads {@see wc_get_base_location()} — WooCommerce's OWN accessor for "the
		 * store's base country/state", NOT a raw `get_option( 'woocommerce_default_country' )`
		 * read (PR #320 review, finding 3): `wc_get_base_location()` runs the store's location
		 * through `apply_filters( 'woocommerce_get_base_location', ... )` first, which a
		 * multi-store, multi-vendor, or geo plugin may hook to answer something other than the
		 * literal option value — bypassing it made this façade's idea of "the store's base
		 * country" quietly diverge from WooCommerce's own. This is a PLAIN function, not the
		 * `WC()` singleton accessor: the earlier justification for reading `get_option()` directly
		 * ("a `WC()`-mediated call would poison Brain Monkey's function table", see
		 * `Checkout_Config::wc_states()`'s own docblock for that measured cost) conflated the two
		 * — `wc_get_base_location()` is exactly as stubbable per-test as `get_option()` itself
		 * (`Brain\Monkey\Functions\when( 'wc_get_base_location' )`, the same convention already
		 * used elsewhere in this codebase, e.g.
		 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::add_countries_admin_notices()}'s own
		 * `wc_get_base_location()['country'] ?? ''` read), and calling it poisons nothing.
		 * `wc_get_base_location()` already splits WooCommerce's `COUNTRY:STATE` option format
		 * (e.g. `RU:*` for a merchant who picked a country without naming a state) into
		 * `['country' => ..., 'state' => ...]` — a raw, un-split read would treat the literal
		 * string `'RU:*'` as the country code, which is not a well-formed ISO-3166 alpha-2 code
		 * and is exactly the class of mistake that once leaked WooCommerce's `*` "no state"
		 * sentinel to a customer's «Регион» field (gotcha this project has already paid for on the
		 * STATE half of this same option) — {@see self::parse_country_component()} validates the
		 * country component regardless.
		 *
		 * Step 3 (the filter) now runs on the FINAL resolved value on EVERY call, not only when
		 * step 2 yields nothing usable (PR #320 review, finding 2 — the WordPress convention, and
		 * the only reading under which the filter is an actual escape hatch): on a real
		 * WooCommerce install `woocommerce_default_country` is never unset or malformed —
		 * `WC_Install::create_options()` `add_option()`s the General-settings default (`'US:CA'`)
		 * on activation, and the settings page's own country dropdown cannot emit anything else —
		 * so a filter gated on "step 2 answered nothing" could NEVER fire on a real store, and this
		 * project's own worked example ("a Kazakhstan store hooks this to return `KZ`") was
		 * unreachable: a Kazakhstan store's `woocommerce_default_country` already resolves to `KZ`
		 * via step 2, leaving the filter nothing to add. Running it on the final value instead lets
		 * a plugin override the STORE'S OWN base country too, not merely stand in for a missing
		 * one — the only way this filter is ever consulted on a real install. Its own result is
		 * re-validated exactly like step 2's; a non-string return degrades silently rather than
		 * ever being cast (PR #320 review, finding 5 — `(string) $object` on a misbehaving filter's
		 * return would fatal the checkout render, not "degrade to the hard RU default" as promised),
		 * and a string that fails validation (or an unset filter, the default) leaves the
		 * already-resolved value untouched.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Reads {@see wc_get_base_location()} instead of a raw `get_option()` call,
		 *              and applies {@see self::FILTER_DEFAULT_COUNTRY} to the FINAL resolved value
		 *              on every call rather than only when the store setting is unusable (PR #320
		 *              review, findings 2 and 3).
		 *
		 * @return string Upper-cased ISO-3166 alpha-2 country code — never empty, never malformed.
		 */
		public function resolve_default_country(): string {
			$resolved = self::parse_country_component( self::base_location_country() );

			if ( '' === $resolved ) {
				$resolved = 'RU';
			}

			/**
			 * Filters the FINAL resolved fallback country for the checkout-field ->
			 * WooCommerce-store-setting -> `RU` chain (issue #296) — see this method's own
			 * docblock (PR #320 review, finding 2) for why this runs on EVERY resolution, not
			 * only when the store's own base location is unusable.
			 *
			 * @since 2.0.2
			 *
			 * @param string $resolved The chain's own answer before this filter runs — the
			 *                         store's base-location country, or `'RU'` when that is
			 *                         unusable.
			 */
			$filtered = apply_filters( self::FILTER_DEFAULT_COUNTRY, $resolved );

			if ( is_string( $filtered ) ) {
				$parsed = self::parse_country_component( $filtered );

				if ( '' !== $parsed ) {
					return $parsed;
				}
			}

			return $resolved;
		}

		/**
		 * The store's own base-location country, straight from
		 * {@see wc_get_base_location()} — see {@see self::resolve_default_country()}'s own
		 * docblock for why this reads WooCommerce's accessor rather than the raw option.
		 *
		 * @since 2.0.2
		 *
		 * @return string Raw country component, possibly empty or malformed — validated by the
		 *                 caller via {@see self::parse_country_component()}.
		 */
		private static function base_location_country(): string {
			$location = wc_get_base_location();

			return is_array( $location ) ? (string) ( $location['country'] ?? '' ) : '';
		}

		/**
		 * Splits WooCommerce's `COUNTRY:STATE` option format (e.g. `'RU:*'`) and validates the
		 * country component alone — see {@see self::resolve_default_country()}'s own docblock for
		 * why a bare, un-split read is exactly the mistake this method exists to prevent.
		 *
		 * @since 2.0.2
		 *
		 * @param string $raw Raw option/filter value, possibly `COUNTRY:STATE`, possibly malformed.
		 *
		 * @return string Upper-cased 2-letter country code, or `''` when malformed/absent.
		 */
		private static function parse_country_component( string $raw ): string {
			$country = strtoupper( trim( explode( ':', $raw, 2 )[0] ) );

			return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
		}

		/**
		 * Whether a provider covers `$country` (spec D2: a STATIC,
		 * PHP-answerable list — see {@see Location_Provider::get_countries()}
		 * — so this needs no network call and can arbitrate server-side).
		 *
		 * `$level` decides WHICH provider is consulted (D15 gate fix, block
		 * PR-B). Omitted (or `null`): asks about the ACTIVE provider alone —
		 * the original, still-supported contract. Given a level: delegates to
		 * {@see self::provider_for_level()}'s own joint country+level check
		 * (D15 amendment follow-up: per-country suggest levels) — a country a
		 * provider statically lists but does not actually serve `$level` FOR
		 * (e.g. DaData + `address` in a GeoNames-tier country) is correctly
		 * reported unsupported, not just "no provider resolves the level at
		 * all" as before that check existed.
		 *
		 * Degrades to `false` — never throws — for every unanswerable case: no
		 * provider resolves for the given (or active) selector, or a
		 * `$country` that is not a well-formed ISO-3166 alpha-2 code once
		 * normalized the SAME way {@see Location_Record::from_array()} and
		 * {@see Location_Scope} themselves normalize a country (trim +
		 * upper-case; see their own `/^[A-Z]{2}$/` validation). Unlike those
		 * two constructors, this is a read-side predicate a caller (REST, the
		 * checkout config block) may hand raw, possibly-malformed request
		 * input to, so it answers safely rather than rejecting like they do.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$level` parameter (D15 gate fix,
		 *              block PR-B): the country check must consult whichever
		 *              provider actually serves the requested level, not the
		 *              active provider unconditionally.
		 * @since 2.0.2 The `$level`-given branch now also consults the
		 *              resolved provider's PER-COUNTRY suggest levels (D15
		 *              amendment follow-up), not merely its static country
		 *              list.
		 *
		 * @param string      $country Country code, any case/whitespace.
		 * @param string|null $level   One of {@see Location_Record::LEVELS},
		 *                             or `null` to ask about the active
		 *                             provider alone.
		 *
		 * @return bool
		 *
		 * @throws \InvalidArgumentException When `$level` is given and is not
		 *                                    one of {@see Location_Record::LEVELS}
		 *                                    — see {@see self::provider_for_level()}.
		 */
		public function is_country_supported( string $country, ?string $level = null ): bool {
			if ( null !== $level ) {
				return null !== $this->provider_for_level( $level, $country );
			}

			$provider = $this->registry->get_active_provider();

			if ( null === $provider ) {
				return false;
			}

			$normalized = strtoupper( trim( $country ) );

			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $normalized ) ) {
				return false;
			}

			return in_array( $normalized, $provider->get_countries(), true );
		}

		/**
		 * The union, across the WHOLE D15 provider chain, of every country ANY
		 * resolved provider covers at ANY level (D15 gate fix, block PR-B).
		 *
		 * Walks {@see Location_Record::LEVELS}, resolving each level's
		 * provider via {@see self::provider_for_level()} and collecting
		 * {@see Location_Provider::get_countries()} — deduplicated by provider
		 * id, so a provider serving more than one level (the common case: the
		 * bundled DaData fallback serves all three) is only ever asked once.
		 * This is the set the checkout config's `location.countries` block
		 * needs: "can the layer do something for this country AT ALL", not
		 * "does the active provider alone cover it" — a country only a
		 * FALLBACK serves at one level must still surface here.
		 *
		 * Normalized the same way {@see self::is_country_supported()}
		 * normalizes a single country (trim + upper-case), so a caller
		 * intersecting this against its own list never has to renormalize
		 * either side.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] Normalized (upper-case) ISO-3166 alpha-2 codes,
		 *                  deduplicated, in no particular order.
		 */
		public function get_supported_countries(): array {
			$seen_provider_ids = [];
			$countries         = [];

			foreach ( Location_Record::LEVELS as $level ) {
				$provider = $this->provider_for_level( $level );

				if ( null === $provider || isset( $seen_provider_ids[ $provider->get_id() ] ) ) {
					continue;
				}

				$seen_provider_ids[ $provider->get_id() ] = true;

				foreach ( $provider->get_countries() as $code ) {
					$countries[ strtoupper( trim( (string) $code ) ) ] = true;
				}
			}

			return array_keys( $countries );
		}

		/**
		 * Which suggest LEVELS the D15 chain resolves for ONE specific country
		 * — `region`/`settlement`/`address` => whether SOME configured
		 * provider serves that level FOR THIS COUNTRY (D15 amendment
		 * follow-up: per-country suggest levels).
		 *
		 * This is the per-country twin of the country-blind question a single
		 * flat `levels` map could once answer: DaData genuinely serves
		 * `address` in RU/BY/KZ/UZ but not in AM/AZ/KG/TJ/TM, so "does some
		 * provider serve `address`" no longer has one true/false answer across
		 * every country the layer covers — it depends on which country the
		 * customer is currently addressing. {@see Checkout_Config} calls this
		 * once per country in its own `countries` list to build a per-country
		 * `levels` map, rather than the framework guessing which single
		 * country's answer the client will need next — the client cannot
		 * refresh the config on a plain country-field change (no round-trip in
		 * that path today), so the config ships the WHOLE map up front.
		 *
		 * Never reveals WHICH provider serves a level (spec D15) — this reads
		 * only {@see self::provider_for_level()}'s null/non-null answer, never
		 * the resolved instance's own id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO-3166 alpha-2 country code, any case/whitespace.
		 *
		 * @return array{region: bool, settlement: bool, address: bool}
		 */
		public function get_levels_for_country( string $country ): array {
			$levels = [];

			foreach ( Location_Record::LEVELS as $level ) {
				$levels[ $level ] = null !== $this->provider_for_level( $level, $country );
			}

			return $levels;
		}

		/**
		 * Which PROVIDER owns each suggest LEVEL for ONE specific country —
		 * `region`/`settlement`/`address` => the id of the provider the D15
		 * chain resolves for that level in that country, or `''` (never
		 * `null`) when no provider serves it there.
		 *
		 * This is the D15 spec's one deliberate exception (issue #352): the
		 * sibling {@see self::get_levels_for_country()} exists specifically so
		 * the client never learns WHICH provider serves a level, only WHETHER
		 * one does — but nothing NEW leaks by adding this method. The provider
		 * id is ALREADY visible to the client on every persisted record
		 * ({@see Location_Record::to_array()} includes `provider_id`, and
		 * {@see Location_Record::key()} is literally `provider_id:native_id`),
		 * so this method only republishes, ahead of a pick, information the
		 * client already receives the moment it makes one. What genuinely
		 * changes is that the client can now act on ownership BEFORE posting a
		 * record — see below.
		 *
		 * The reason this is needed at all: a store can run a mixed provider
		 * chain, e.g. the active provider serving `region`/`settlement` and
		 * the bundled fallback serving `address`. {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::rebuild_chain()}
		 * only keeps a shallower stored record when the new one can PROVE
		 * kinship with it ({@see Location_Record::is_within()}, which requires
		 * every ancestor to share the SAME provider — issue #334, deliberately
		 * NOT weakened here: a Moscow settlement must not survive a
		 * Saint-Petersburg address, and cross-provider kinship cannot be
		 * proven in principle). Without knowing which provider owns which
		 * level, the client cannot tell a same-provider pick (safe to enter
		 * the chain) from a foreign one (would silently amputate every
		 * shallower level once posted) — it would have to post first and find
		 * out never. `location-cascade.js`'s `mayEnterChain()` is the
		 * consumer this method exists for.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO-3166 alpha-2 country code, any case/whitespace.
		 *
		 * @return array{region: string, settlement: string, address: string}
		 */
		public function get_level_owners_for_country( string $country ): array {
			$owners = [];

			foreach ( Location_Record::LEVELS as $level ) {
				$provider          = $this->provider_for_level( $level, $country );
				$owners[ $level ] = null !== $provider ? $provider->get_id() : '';
			}

			return $owners;
		}

		/**
		 * Walks the D15 provider chain for one suggest LEVEL: the active
		 * provider first, when it is configured and declares the level in
		 * {@see Location_Provider::get_suggest_levels()}; otherwise the
		 * bundled provider (registered under
		 * {@see Location_Provider_Registry::DEFAULT_PROVIDER_ID}), when it is
		 * ALSO registered, configured, and itself declares the level;
		 * otherwise `null` — that field stays native (spec §4.7).
		 *
		 * The fallback is consulted ONLY when the active provider does not
		 * already answer for this level: when the active provider itself
		 * serves it, the fallback's `is_configured()`/`get_suggest_levels()`
		 * are never even called. This is load-bearing, not an
		 * optimization-only detail: a later task exposes "which levels are
		 * served" to the client without ever revealing WHICH provider serves
		 * them, so the chain must actually behave as a short-circuiting
		 * priority order, not merely report one.
		 *
		 * `$country` (optional, D15 amendment follow-up: per-country suggest
		 * levels, e.g. DaData genuinely serves `address` in RU/BY/KZ/UZ but
		 * not in AM/AZ/KG/TJ/TM): when given, a candidate is eligible ONLY when
		 * it ALSO covers `$country` ({@see Location_Provider::get_countries()})
		 * AND its {@see Location_Provider::get_suggest_levels()} answer FOR
		 * THAT COUNTRY includes `$level`. Omitted (`null`, the default):
		 * behaves EXACTLY as before this parameter was added — no country
		 * check at all, and each candidate's country-blind (unnarrowed)
		 * level set decides eligibility — so every pre-existing call site
		 * (including this method's own 1-argument form) keeps its original
		 * meaning unchanged.
		 *
		 * `address_suggestions` STORE GATE (Task 10; issue #362; design
		 * S3/§4.2/§7): for `$level === LEVEL_ADDRESS`, the chain walk is
		 * skipped entirely — `$resolved` is forced to `null` — when
		 * {@see Location_Provider_Registry::get_address_suggestions_raw()}
		 * answers `false`. Placed BEFORE the chain walk (never after) so
		 * every OTHER method built on top of this one —
		 * {@see self::get_levels_for_country()}, {@see self::get_level_owners_for_country()},
		 * {@see self::is_country_supported()}, the REST `/suggest` route
		 * ({@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::perform_suggest()})
		 * — agrees automatically, without each having to re-check the switch
		 * itself. The gate reads the RAW stored flag, never the clamped
		 * {@see Location_Provider_Registry::is_address_suggestions_enabled()}
		 * — see that raw reader's own docblock for why consulting the
		 * clamped value here would be circular. The FILTER below still runs
		 * even while the gate is closed (framework rule: never remove an
		 * extension seam) — a plugin hooking {@see self::FILTER_PROVIDER_FOR_LEVEL}
		 * can still swap in a provider of its own for `address`; the store
		 * switch only decides what THIS chain resolves on its own.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$country` parameter.
		 * @since 2.0.2 Added the `address_suggestions` store gate for
		 *              `LEVEL_ADDRESS` (Task 10; issue #362).
		 *
		 * @param string      $level   One of {@see Location_Record::LEVELS}.
		 * @param string|null $country ISO-3166 alpha-2 country code, or `null`
		 *                              for the country-blind chain walk.
		 *
		 * @return Location_Provider|null
		 *
		 * @throws \InvalidArgumentException When `$level` is not one of
		 *                                    {@see Location_Record::LEVELS}.
		 */
		public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Service::provider_for_level(): "level" must be one of %s, got "%s".',
						implode( ', ', Location_Record::LEVELS ),
						$level
					)
				);
			}

			if ( Location_Record::LEVEL_ADDRESS === $level && ! $this->registry->get_address_suggestions_raw() ) {
				$resolved = null;
			} else {
				$resolved = $this->resolve_provider_for_level( $level, $country );
			}

			/**
			 * Filters the D15 chain's resolved provider for one suggest level.
			 *
			 * @since 2.0.2
			 * @since 2.0.2 Added the `$country` argument.
			 *
			 * @param Location_Provider|null $resolved The chain's own answer
			 *                                          (chosen → bundled
			 *                                          fallback → null; forced
			 *                                          `null` for `address`
			 *                                          while the store switch
			 *                                          is off, without ever
			 *                                          walking the chain).
			 * @param string                 $level    The level being resolved.
			 * @param string|null             $country  The country the chain was
			 *                                          walked for, or `null` for
			 *                                          the country-blind walk.
			 */
			return apply_filters( self::FILTER_PROVIDER_FOR_LEVEL, $resolved, $level, $country );
		}


		/**
		 * Whether a provider is registered under `$provider_id` — a thin proxy
		 * for {@see Location_Provider_Registry::has_provider()} so a caller
		 * holding only this façade (e.g.
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}) never
		 * needs its own reference to the registry singleton.
		 *
		 * Answers "is this id registered AT ALL", regardless of whether the
		 * provider is configured or serves any particular level/country —
		 * {@see self::provider_by_id()} is the eligibility check built on top
		 * of this one.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id Provider id.
		 *
		 * @return bool
		 */
		public function has_provider( string $provider_id ): bool {
			return $this->registry->has_provider( $provider_id );
		}


		/**
		 * Gets ONE registered provider by its raw id, with NO eligibility gating
		 * (#488 slice 3, D5) — unlike {@see self::provider_by_id()}, which
		 * additionally checks `is_configured()` and per-level/country service.
		 *
		 * The D5 lazy-verification step needs the OPPOSITE of that gating: it must
		 * always attempt `resolve_key()` on the SAME provider that produced a
		 * stored key, so an "unconfigured" or "does not serve this level right
		 * now" condition surfaces as a THROWN, caught, `failed`
		 * {@see Popular_Settlement_Verification} outcome — never a silent skip
		 * that would leave a stale row unverified with nothing logged (spec D6:
		 * "failed" is not "gone", and a provider outage must never block a
		 * purchase, but it must still be OBSERVABLE).
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id The provider's own {@see Location_Provider::get_id()}.
		 *
		 * @return Location_Provider|null Null only when no such provider is registered at all.
		 */
		public function get_registered_provider( string $provider_id ): ?Location_Provider {
			return $this->registry->get_providers()[ $provider_id ] ?? null;
		}

		/**
		 * Gets the shared {@see Popular_Settlement_Store} instance (#488 slice 3)
		 * — a thin delegate to {@see Location_Provider_Registry::popular_settlement_store()}
		 * so {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller} (which
		 * only ever holds a {@see self} instance, never the registry directly —
		 * see this façade's own constructor docblock) can reach the SAME store
		 * instance enrolment already writes to.
		 *
		 * @since 2.0.2
		 *
		 * @return Popular_Settlement_Store
		 */
		public function popular_settlement_store(): Popular_Settlement_Store {
			return $this->registry->popular_settlement_store();
		}

		/**
		 * Gets the active provider's popular-settlements list for ONE country
		 * (issue #530 — #488's customer-facing half: the list existed, but nothing
		 * served it to the checkout), in the same wire shape
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::to_response_records()}
		 * already uses for `/suggest`/`/list`: `{ key, label, level, record }`, `record`
		 * the settlement's own {@see Location_Record::to_array()} untouched — so a pick
		 * seeded from this list posts to `/select` byte-identical to a search pick
		 * (spec D1: "picking from the popular list must be indistinguishable from
		 * picking through search").
		 *
		 * Gated exactly like
		 * {@see \Woodev\Framework\Shipping\Location\Popular_Settlements_Tools}'s own D3
		 * rule: `[]` — never present-and-partial — when there is no active provider, or
		 * it does not declare {@see Location_Provider::CAPABILITY_RESOLVE_KEY} (spec
		 * D4: "no capability, no popular list at all"). Already ranked
		 * ({@see Popular_Settlement_Store::all_for_provider()} sorts by `order_count`
		 * DESC) — this method only narrows to `$country`, since the store itself is
		 * NOT country-scoped (one provider's rows can span every country it serves).
		 *
		 * MEASURED, not the plan's original guess: {@see Location_Record::region()}
		 * carries only `{ name, type }` — no key — so there is no single "region key"
		 * to carry per entry the way an earlier draft of this feature assumed. The
		 * mechanism the client actually needs for local region filtering is
		 * {@see Location_Record::ancestors()}, already present verbatim inside
		 * `record` below (the same flat ancestor-key SET
		 * {@see Location_Record::is_within()} already uses server-side, and
		 * `location-cascade.js`'s own `scopeKeyFor()` already resolves the selected
		 * region's key to compare against) — never re-derived or invented here.
		 *
		 * @since 2.1.0
		 *
		 * @param string $country ISO-3166 alpha-2 country code.
		 *
		 * @return array<int, array{key: string, label: string, level: string, record: array<string, mixed>}>
		 */
		public function get_popular_settlements_for_country( string $country ): array {
			$provider = $this->registry->get_active_provider();

			if ( null === $provider || ! in_array( Location_Provider::CAPABILITY_RESOLVE_KEY, $provider->get_capabilities(), true ) ) {
				return [];
			}

			$country = strtoupper( trim( $country ) );
			$mapped  = [];

			foreach ( $this->popular_settlement_store()->all_for_provider( $provider->get_id() ) as $entry ) {
				if ( $entry->country() !== $country ) {
					continue;
				}

				$record   = $entry->record();
				$mapped[] = [
					'key'    => $record->key(),
					'label'  => esc_html( $record->label() ),
					'level'  => $record->level(),
					'record' => $record->to_array(),
				];
			}

			return $mapped;
		}

		/**
		 * Resolves ONE SPECIFIC registered provider for a suggest level — the
		 * admin-override counterpart to {@see self::provider_for_level()}'s D15
		 * chosen -> bundled-fallback chain walk (issue #380).
		 *
		 * Where {@see self::provider_for_level()} lets the CHAIN pick a
		 * provider, this lets the CALLER name one explicitly (e.g. an admin
		 * previewing a provider other than the store's currently active one)
		 * and applies the exact same per-candidate eligibility check the chain
		 * itself uses — `is_configured()` plus
		 * {@see self::provider_serves_level()} — to that one named id instead
		 * of walking chosen -> fallback. A caller MUST already have confirmed
		 * the id is registered via {@see self::has_provider()} before treating
		 * a `null` return as "not eligible right now" rather than "unknown id"
		 * — this method itself does not distinguish the two, since both
		 * legitimately answer `null`.
		 *
		 * Applies the SAME `address_suggestions` store gate
		 * {@see self::provider_for_level()} applies for
		 * `Location_Record::LEVEL_ADDRESS` — an override must not bypass a
		 * store-wide switch the ordinary chain itself already honours.
		 *
		 * Deliberately bypasses {@see self::FILTER_PROVIDER_FOR_LEVEL}: that
		 * filter's own docblock documents it as filtering the D15 CHAIN's
		 * resolution specifically; an explicit override never walks that chain,
		 * so running the chain's filter over it would misrepresent what this
		 * method actually did.
		 *
		 * @since 2.0.2
		 *
		 * @param string      $provider_id Provider id (assumed already validated
		 *                                 via {@see self::has_provider()}).
		 * @param string      $level       One of {@see Location_Record::LEVELS}.
		 * @param string|null $country     ISO-3166 alpha-2 country code, or
		 *                                 `null` for the country-blind check.
		 *
		 * @return Location_Provider|null
		 *
		 * @throws \InvalidArgumentException When `$level` is not one of
		 *                                    {@see Location_Record::LEVELS}.
		 */
		public function provider_by_id( string $provider_id, string $level, ?string $country = null ): ?Location_Provider {
			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Service::provider_by_id(): "level" must be one of %s, got "%s".',
						implode( ', ', Location_Record::LEVELS ),
						$level
					)
				);
			}

			if ( Location_Record::LEVEL_ADDRESS === $level && ! $this->registry->get_address_suggestions_raw() ) {
				return null;
			}

			$candidate = $this->registry->get_providers()[ $provider_id ] ?? null;

			if ( null === $candidate || ! $candidate->is_configured() ) {
				return null;
			}

			return $this->provider_serves_level( $candidate, $level, $country ) ? $candidate : null;
		}


		/**
		 * Whether the D15 chain COULD serve one suggest LEVEL — bypassing
		 * BOTH the `address_suggestions` store gate {@see self::provider_for_level()}
		 * applies for `LEVEL_ADDRESS` AND {@see self::FILTER_PROVIDER_FOR_LEVEL}
		 * (Task 10; issue #362; design S3).
		 *
		 * Answers "could the chain serve this level if the store allowed it",
		 * deliberately NOT "does it right now" — {@see self::provider_for_level()}
		 * already answers that. This exists specifically for the admin
		 * settings surface ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::is_address_suggestions_available()}):
		 * deciding whether the `address_suggestions` control should be
		 * disabled needs the underlying CAPABILITY, not the runtime answer —
		 * a store where the switch is currently off must still see the
		 * control ENABLED (merely unchecked) whenever turning it back on
		 * would actually do something. Reading through
		 * {@see self::provider_for_level()} for that purpose would be
		 * self-defeating (it would always answer `null` for `address` while
		 * the switch is off, regardless of what the chain could otherwise
		 * resolve), and reading through the filter would let one plugin's
		 * request-scoped swap decide whether every OTHER merchant sees the
		 * control as available at all.
		 *
		 * Delegates straight to {@see self::resolve_provider_for_level()} —
		 * the same private chain walk {@see self::provider_for_level()}
		 * itself uses, minus that method's own gate and filter.
		 *
		 * @since 2.0.2
		 *
		 * @param string      $level   One of {@see Location_Record::LEVELS}.
		 * @param string|null $country ISO-3166 alpha-2 country code, or `null`
		 *                              for the country-blind chain walk.
		 *
		 * @return bool
		 *
		 * @throws \InvalidArgumentException When `$level` is not one of
		 *                                    {@see Location_Record::LEVELS}.
		 */
		public function is_level_servable( string $level, ?string $country = null ): bool {
			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Service::is_level_servable(): "level" must be one of %s, got "%s".',
						implode( ', ', Location_Record::LEVELS ),
						$level
					)
				);
			}

			return null !== $this->resolve_provider_for_level( $level, $country );
		}

		/**
		 * The chain-walk itself, before {@see self::FILTER_PROVIDER_FOR_LEVEL}
		 * runs — see {@see self::provider_for_level()} for the full contract.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$country` parameter.
		 *
		 * @param string      $level   Already-validated level.
		 * @param string|null $country ISO-3166 alpha-2 country code, or `null`.
		 *
		 * @return Location_Provider|null
		 */
		private function resolve_provider_for_level( string $level, ?string $country ): ?Location_Provider {
			$chosen = $this->registry->get_active_provider();

			if ( null !== $chosen && $chosen->is_configured() && $this->provider_serves_level( $chosen, $level, $country ) ) {
				return $chosen;
			}

			$fallback = $this->registry->get_providers()[ Location_Provider_Registry::DEFAULT_PROVIDER_ID ] ?? null;

			if ( null !== $fallback && $fallback->is_configured() && $this->provider_serves_level( $fallback, $level, $country ) ) {
				return $fallback;
			}

			return null;
		}

		/**
		 * Whether `$provider` is an eligible chain candidate for `$level`,
		 * optionally narrowed by `$country` — the joint check both
		 * {@see self::resolve_provider_for_level()} candidates run.
		 *
		 * `$country` given: `$provider` must ALSO cover it
		 * ({@see Location_Provider::get_countries()}) AND its per-country
		 * {@see Location_Provider::get_suggest_levels()} answer must include
		 * `$level`. `$country` omitted: no country check at all, and the
		 * country-blind (unnarrowed) level set decides — identical to this
		 * class's behavior before the `$country` parameter existed.
		 *
		 * PUBLIC (widened from `private`, #375/#377): {@see Location_Provider_Registry::register_settings()}
		 * reuses this EXACT predicate — never a hand-rolled one — to decide,
		 * for every registered (not just the active) provider, whether it
		 * serves `address` for the STORE's own country, when building the
		 * bundled default provider's `show_if` condition. Calling this
		 * instead of inlining `in_array( $level, $provider->get_suggest_levels( $country ), true )`
		 * matters: the country-coverage gate above (`get_countries()`) is
		 * NOT part of `get_suggest_levels()` itself, and a caller skipping
		 * it would silently disagree with every other D15 chain-walk call
		 * site in this class.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Widened from `private` to `public` (#375/#377) so
		 *              {@see Location_Provider_Registry} can reuse it for a
		 *              non-active, arbitrary provider.
		 *
		 * @param Location_Provider $provider Candidate provider.
		 * @param string            $level    One of {@see Location_Record::LEVELS}.
		 * @param string|null       $country  ISO-3166 alpha-2 country code, or `null`.
		 *
		 * @return bool
		 */
		public function provider_serves_level( Location_Provider $provider, string $level, ?string $country ): bool {
			if ( null === $country ) {
				return in_array( $level, $provider->get_suggest_levels(), true );
			}

			$normalized = strtoupper( trim( $country ) );

			if ( ! in_array( $normalized, $provider->get_countries(), true ) ) {
				return false;
			}

			return in_array( $level, $provider->get_suggest_levels( $normalized ), true );
		}

		/**
		 * Walks the D15 chain (chosen → bundled fallback → null) for the
		 * {@see Location_Provider::CAPABILITY_LIST} capability (Task 13) — the
		 * `GET woodev/v1/location/list` route's own provider resolution, mirroring
		 * {@see self::provider_for_level()}'s shape exactly but gating on the
		 * `list` capability instead of a suggest level. `list_localities()` is
		 * NOT itself a per-level D15 fallback concept the way `suggest()` is
		 * (spec D15 is specifically about per-LEVEL suggest support); this chain
		 * exists so `/location/list` degrades the same forgiving way `/suggest`
		 * does when the CHOSEN provider cannot enumerate but the bundled fallback
		 * theoretically could (today: never — DaData has no `list` capability at
		 * all — but the chain costs nothing to keep consistent with every other
		 * D15-shaped resolution in this façade, and a future `list`-capable
		 * bundled provider would be served correctly with zero caller changes).
		 *
		 * `$country` (optional): when given, a candidate is eligible only when it
		 * ALSO covers it ({@see Location_Provider::get_countries()}) — mirrors
		 * {@see self::provider_serves_level()}'s own country check. Omitted: no
		 * country check, only the capability itself decides (used by
		 * {@see Location_Provider_Registry::get_offered_field_modes()}'s own
		 * gate, which is intentionally country-blind — mode OFFERING is a
		 * store-wide decision, not a per-country one).
		 *
		 * @since 2.0.2
		 *
		 * @param string|null $country ISO-3166 alpha-2 country code, or `null`
		 *                             for the country-blind chain walk.
		 *
		 * @return Location_Provider|null
		 */
		public function provider_for_list( ?string $country = null ): ?Location_Provider {
			$chosen = $this->registry->get_active_provider();

			if ( null !== $chosen && $chosen->is_configured() && $this->provider_serves_list( $chosen, $country ) ) {
				return $chosen;
			}

			$fallback = $this->registry->get_providers()[ Location_Provider_Registry::DEFAULT_PROVIDER_ID ] ?? null;

			if ( null !== $fallback && $fallback->is_configured() && $this->provider_serves_list( $fallback, $country ) ) {
				return $fallback;
			}

			return null;
		}

		/**
		 * Whether `$provider` is an eligible {@see self::provider_for_list()}
		 * candidate: declares {@see Location_Provider::CAPABILITY_LIST} and,
		 * when `$country` is given, also covers it.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider $provider Candidate provider.
		 * @param string|null       $country  ISO-3166 alpha-2 country code, or `null`.
		 *
		 * @return bool
		 */
		private function provider_serves_list( Location_Provider $provider, ?string $country ): bool {
			if ( ! in_array( Location_Provider::CAPABILITY_LIST, $provider->get_capabilities(), true ) ) {
				return false;
			}

			if ( null === $country ) {
				return true;
			}

			return in_array( strtoupper( trim( $country ) ), $provider->get_countries(), true );
		}

		/**
		 * Gets the field-presentation modes the store setting is currently
		 * allowed to offer (Task 13; spec D7) — thin pass-through to
		 * {@see Location_Provider_Registry::get_offered_field_modes()}.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_offered_field_modes(): array {
			return $this->registry->get_offered_field_modes();
		}

		/**
		 * Gets the store's REGION-level field-presentation mode (issue #380 —
		 * split from the legacy single mode) — thin pass-through to
		 * {@see Location_Provider_Registry::get_field_mode_region()}, clamped
		 * against {@see self::get_offered_field_modes()} (and, on TOP of
		 * that, against `region_field` being removed — issue #369 closure)
		 * so a stale saved value can never name a mode the current active
		 * provider does not back.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_field_mode_region(): string {
			return $this->registry->get_field_mode_region();
		}

		/**
		 * Gets the store's SETTLEMENT (НП) level field-presentation mode
		 * (issue #380 — split from the legacy single mode) — thin
		 * pass-through to {@see Location_Provider_Registry::get_field_mode_settlement()},
		 * clamped against {@see self::get_offered_field_modes()} exactly like
		 * {@see self::get_field_mode_region()} — but without that method's
		 * `region_field` clamp.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_field_mode_settlement(): string {
			return $this->registry->get_field_mode_settlement();
		}

		/**
		 * Whether the merchant has opted in to letting the customer submit a
		 * settlement value the active provider does not carry (#528) — thin
		 * pass-through to {@see Location_Provider_Registry::is_custom_settlement_allowed()}.
		 *
		 * @since 2.0.3
		 *
		 * @return bool
		 */
		public function is_custom_settlement_allowed(): bool {
			return $this->registry->is_custom_settlement_allowed();
		}

		/**
		 * Whether the `related-list` mode's own region injector
		 * ({@see Location_Provider_Registry::inject_related_list_states()})
		 * itself wrote `$country`'s `woocommerce_states` options THIS request AND
		 * those options are still what WooCommerce is serving right now — thin
		 * pass-through to {@see Location_Provider_Registry::owns_region_states()}.
		 *
		 * This is the precision {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::build_location_block()}
		 * needs for the issue #294 arbitration: a non-empty state list for a
		 * country can come from WooCommerce's own native list, a plugin's §8
		 * carrier takeover, OR this layer's own related-list injection — only
		 * the last one is NOT a conflict, and only when nothing ran AFTER this
		 * layer's injector to clobber it (PR #304 review finding 3).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Takes the caller's own FINAL `woocommerce_states` read
		 *              (PR #304 review finding 3) — see
		 *              {@see Location_Provider_Registry::owns_region_states()}'s
		 *              own docblock for why.
		 *
		 * @param string                $country      ISO-3166 alpha-2 country code, any case/whitespace.
		 * @param array<string, string> $final_states The country's FINAL registered WC states.
		 *
		 * @return bool
		 */
		public function owns_region_states( string $country, array $final_states ): bool {
			return $this->registry->owns_region_states( $country, $final_states );
		}
	}

endif;
