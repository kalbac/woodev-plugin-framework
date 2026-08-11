<?php
/**
 * Woodev Location Provider Interface
 *
 * The provider contract (spec §4.1, D1): a source of locality/address data — DaData,
 * a carrier's own dictionary, or a future custom source. Mirrors the SHAPE of WC's own
 * Address Autocomplete Provider (id / countries / search / select), but is its own
 * contract, not built on WC's — see the spec's D1 rationale (WC's own provider only
 * hosts search on `address_1`, returns flat strings, and has no cascade/scoping).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Provider' ) ) :

	/**
	 * Location provider contract.
	 *
	 * `get_id()`, `get_name()`, `get_countries()`, `get_suggest_levels()` and
	 * `suggest()` are REQUIRED of every implementation. `list_localities()`,
	 * `locate()` and `normalize()` are OPTIONAL — {@see self::get_capabilities()}
	 * reports which of them a given provider actually implements, and a caller
	 * MUST check that before calling one: an unimplemented optional method throws
	 * `\BadMethodCallException` rather than silently no-op'ing (see
	 * {@see Abstract_Location_Provider}, the reference implementation of this
	 * discovery mechanism).
	 *
	 * @since 2.0.2
	 */
	interface Location_Provider {

		/**
		 * Capability name: full enumeration of localities within a scope (e.g. the
		 * CDEK dictionary's `/location/cities?region_code=`), feeding the "related
		 * list" client mode (spec D7).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const CAPABILITY_LIST = 'list';

		/**
		 * Capability name: geo-IP → record (e.g. DaData's `iplocate/address`),
		 * feeding the `geoip` default-locality policy (spec D11).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const CAPABILITY_LOCATE = 'locate';

		/**
		 * Capability name: free-form address string → record (e.g. DaData's `clean`
		 * endpoint), absorbing the old `Address_Normalizer::normalize()` use case
		 * (spec D14).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const CAPABILITY_NORMALIZE = 'normalize';

		/**
		 * All valid capability names — the full vocabulary
		 * {@see self::get_capabilities()} may return a subset of.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		public const CAPABILITIES = [ self::CAPABILITY_LIST, self::CAPABILITY_LOCATE, self::CAPABILITY_NORMALIZE ];

		/**
		 * Gets the provider's unique identifier.
		 *
		 * Used as the registry key, as the namespace prefix of every
		 * {@see Locality_Key} this provider produces, and as the store-level
		 * "active provider" setting value.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_id(): string;

		/**
		 * Gets the provider's human-readable label.
		 *
		 * Shown to the merchant when choosing the active provider in the settings
		 * UI. User-facing — Russian, per project convention.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_name(): string;

		/**
		 * Gets the countries this provider covers.
		 *
		 * A STATIC ISO-3166 alpha-2 list — answerable in plain PHP with no network
		 * call. This is deliberate (spec D2): per-country arbitration between our
		 * layer and WC's own Address Autocomplete happens SERVER-SIDE as well as
		 * client-side, and WC's own `canSearch( country )` is JS-only and therefore
		 * invisible to PHP. A provider whose real coverage can only be determined by
		 * calling out to its API must still answer this list from a fixed, hardcoded
		 * (or filtered) set.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] ISO-3166 alpha-2 codes.
		 */
		public function get_countries(): array;

		/**
		 * Gets the levels this provider can answer `suggest()` for.
		 *
		 * A subset of {@see Location_Record::LEVELS}, declared PER LEVEL rather than
		 * as one all-or-nothing flag (spec D15): a city-level dictionary (e.g. CDEK)
		 * has regions and settlements but NO street data, so it must be able to say
		 * "region, settlement — not address" rather than "suggest: yes/no" for the
		 * whole provider. The framework walks a provider chain (chosen provider →
		 * bundled fallback) per level and uses the first that supports it; a level no
		 * configured provider supports leaves that checkout field native.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] Subset of {@see Location_Record::LEVELS}.
		 */
		public function get_suggest_levels(): array;

		/**
		 * Gets the OPTIONAL capabilities this provider actually implements.
		 *
		 * A subset of {@see self::CAPABILITIES}. A caller MUST check this before
		 * calling {@see self::list_localities()}, {@see self::locate()} or
		 * {@see self::normalize()} — calling one this provider does not declare
		 * throws `\BadMethodCallException` (see {@see Abstract_Location_Provider}).
		 * `suggest()` is NOT a capability — it is required of every provider — and
		 * is never reported here; see {@see self::get_suggest_levels()} for its
		 * per-level support instead.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] Subset of {@see self::CAPABILITIES}.
		 */
		public function get_capabilities(): array;

		/**
		 * Query-driven suggestion lookup — REQUIRED of every provider.
		 *
		 * `$scope` names the country, the level being searched, and an optional
		 * parent constraint (a region record when searching settlements, a
		 * settlement record when searching addresses — spec §4.1). Only levels
		 * listed in {@see self::get_suggest_levels()} may be queried; calling this
		 * for an unsupported level is a caller error the framework's own chain-walk
		 * (spec D15) is responsible for avoiding, not this method.
		 *
		 * @since 2.0.2
		 *
		 * @param string         $query Free-text search term, as typed so far.
		 * @param Location_Scope $scope Lookup scope.
		 *
		 * @return Location_Record[] Zero or more matches, contract-shaped (spec D12);
		 *                           the provider's own payload rides along opaque
		 *                           under each record's `raw`.
		 */
		public function suggest( string $query, Location_Scope $scope ): array;

		/**
		 * Full enumeration of localities within a scope — OPTIONAL, only when
		 * {@see self::get_capabilities()} contains {@see self::CAPABILITY_LIST}.
		 *
		 * Feeds the "related list" client mode (spec D7): a WC states select for
		 * regions, a select2 populated with the full per-region list for
		 * settlements. DaData cannot enumerate (query-driven API only) and does not
		 * declare this capability; a dictionary-backed provider (e.g. CDEK's
		 * `/location/regions`, `/location/cities?region_code=`) does.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Scope $scope Lookup scope.
		 *
		 * @return Location_Record[] Every locality within scope.
		 *
		 * @throws \BadMethodCallException When this provider does not declare
		 *                                  {@see self::CAPABILITY_LIST}.
		 */
		public function list_localities( Location_Scope $scope ): array;

		/**
		 * Geo-IP lookup — OPTIONAL, only when {@see self::get_capabilities()}
		 * contains {@see self::CAPABILITY_LOCATE}.
		 *
		 * Feeds the `geoip` default-locality policy (spec D11). A failed/unresolved
		 * lookup returns null rather than throwing — geo-IP misses are an expected,
		 * non-exceptional outcome (the caller decides whether to retry).
		 *
		 * @since 2.0.2
		 *
		 * @param string $ip IPv4 or IPv6 address.
		 *
		 * @return Location_Record|null The resolved locality, or null when the IP
		 *                              could not be resolved to one.
		 *
		 * @throws \BadMethodCallException When this provider does not declare
		 *                                  {@see self::CAPABILITY_LOCATE}.
		 */
		public function locate( string $ip ): ?Location_Record;

		/**
		 * Free-form address normalization — OPTIONAL, only when
		 * {@see self::get_capabilities()} contains {@see self::CAPABILITY_NORMALIZE}.
		 *
		 * Absorbs the old `Address_Normalizer::normalize()` use case (spec D14): a
		 * plugin hands over a free-typed address string (e.g. from an import, or a
		 * legacy order) and gets back a contract-shaped record when the provider can
		 * resolve it. Returns null, not an exception, when the free-form text could
		 * not be resolved to a record.
		 *
		 * @since 2.0.2
		 *
		 * @param string         $free_form Free-form address text.
		 * @param Location_Scope $scope    Lookup scope narrowing the resolution
		 *                                  (country and level at minimum).
		 *
		 * @return Location_Record|null The resolved record, or null when unresolved.
		 *
		 * @throws \BadMethodCallException When this provider does not declare
		 *                                  {@see self::CAPABILITY_NORMALIZE}.
		 */
		public function normalize( string $free_form, Location_Scope $scope ): ?Location_Record;
	}

endif;
