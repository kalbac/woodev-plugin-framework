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
		 * @since 2.0.2
		 *
		 * @return array{record: Location_Record, implicit: bool, saved_at: int}|null
		 */
		public function get_customer_record(): ?array {
			return $this->customer_store->get();
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
		 * Resolves `$plugin`'s carrier identity for the customer's CURRENT
		 * location record — cached (Task 5, spec D9).
		 *
		 * Returns `null` in two situations a caller cannot tell apart from the
		 * return value alone: the customer has no location record at all yet
		 * (this method never even reaches the cache/adapter in that case — see
		 * {@see self::get_customer_record()} first if the distinction
		 * matters), or the carrier genuinely does not serve the customer's
		 * locality (a legitimate, cached answer from
		 * {@see Location_Resolution_Cache::resolve_for()} itself).
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin The plugin
		 *        whose adapter should resolve the customer's current locality.
		 *
		 * @return mixed|null The plugin's carrier identity (opaque to the
		 *                     framework), or `null` (no record yet, or the
		 *                     carrier does not serve this locality).
		 *
		 * @throws \Throwable Re-thrown, after logging, when the adapter itself
		 *                     threw — see {@see Location_Resolution_Cache::resolve_for()}.
		 */
		public function resolve_for( \Woodev\Framework\Shipping\Shipping_Plugin $plugin ) {
			$current = $this->get_customer_record();

			if ( null === $current ) {
				return null;
			}

			return $this->resolution_cache->resolve_for( $plugin, $current['record'] );
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
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$country` parameter.
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

			$resolved = $this->resolve_provider_for_level( $level, $country );

			/**
			 * Filters the D15 chain's resolved provider for one suggest level.
			 *
			 * @since 2.0.2
			 * @since 2.0.2 Added the `$country` argument.
			 *
			 * @param Location_Provider|null $resolved The chain's own answer
			 *                                          (chosen → bundled
			 *                                          fallback → null).
			 * @param string                 $level    The level being resolved.
			 * @param string|null             $country  The country the chain was
			 *                                          walked for, or `null` for
			 *                                          the country-blind walk.
			 */
			return apply_filters( self::FILTER_PROVIDER_FOR_LEVEL, $resolved, $level, $country );
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
		 * @since 2.0.2
		 *
		 * @param Location_Provider $provider Candidate provider.
		 * @param string            $level    One of {@see Location_Record::LEVELS}.
		 * @param string|null       $country  ISO-3166 alpha-2 country code, or `null`.
		 *
		 * @return bool
		 */
		private function provider_serves_level( Location_Provider $provider, string $level, ?string $country ): bool {
			if ( null === $country ) {
				return in_array( $level, $provider->get_suggest_levels(), true );
			}

			$normalized = strtoupper( trim( $country ) );

			if ( ! in_array( $normalized, $provider->get_countries(), true ) ) {
				return false;
			}

			return in_array( $level, $provider->get_suggest_levels( $normalized ), true );
		}
	}

endif;
