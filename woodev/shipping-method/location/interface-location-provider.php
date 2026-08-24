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
		 * Capability name: locality key → the provider's CURRENT record for it, or
		 * `null` when the provider no longer recognises the key (popular-settlements
		 * spec D4). Feeds the `last_verified_at` freshness clock — none of the other
		 * four contract methods accept a bare key (`suggest()` takes a query,
		 * `list_localities()` a scope, `locate()` an IP, `normalize()` free-form
		 * text), so a dead key would otherwise have nothing to re-check it against.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const CAPABILITY_RESOLVE_KEY = 'resolve_key';

		/**
		 * All valid capability names — the full vocabulary
		 * {@see self::get_capabilities()} may return a subset of.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		public const CAPABILITIES = [ self::CAPABILITY_LIST, self::CAPABILITY_LOCATE, self::CAPABILITY_NORMALIZE, self::CAPABILITY_RESOLVE_KEY ];

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
		 * `$country` (optional, D15 amendment) further narrows the answer PER
		 * COUNTRY: a provider whose real-world coverage genuinely varies by
		 * country (DaData: `address` works in RU/BY/KZ/UZ, not in AM/AZ/KG/TJ/TM
		 * — measured, not assumed) reports the narrower set only when a caller
		 * asks about a specific country. Omitted: the UNNARROWED set — every
		 * level this provider can EVER answer, for ANY of its countries — which
		 * is what every country-blind call site (e.g. deciding whether ANY
		 * configured provider serves a level at all) needs.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$country` parameter.
		 *
		 * @param string|null $country ISO-3166 alpha-2 country code, or `null`
		 *                              for the unnarrowed answer.
		 *
		 * @return string[] Subset of {@see Location_Record::LEVELS}.
		 */
		public function get_suggest_levels( ?string $country = null ): array;

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
		 * Gets the provider-specific store-level settings fields (Task 3; spec §4.1:
		 * "Store-level provider settings ... declared by the provider, rendered on the
		 * shared settings surface, stored server-side").
		 *
		 * Returned in the Woodev settings-API `register_setting()` args shape (`name`,
		 * `type`, `default`, `description`, `required`, `sensitive`, `options`, …) —
		 * see `woodev/settings-api/abstract-class-settings.php` — mirroring
		 * {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_settings_fields()}
		 * exactly, so callers never learn a second settings-description vocabulary.
		 * Unlike the {@see self::CAPABILITIES} trio this is REQUIRED of every
		 * provider (not gated behind {@see self::get_capabilities()}) but is not
		 * abstract in {@see Abstract_Location_Provider} either — it defaults to an
		 * empty array there, exactly like {@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider}
		 * returns `[]` for a provider that needs no credential.
		 *
		 * `Location_Provider_Registry` merges EVERY registered provider's fields
		 * (not only the active one's) into the shared `Location_Provider_Registry`
		 * store-level settings surface (D4: tokens are store settings held
		 * server-side), each gated behind a `show_if` condition (ADR-008) on the
		 * `active_provider` setting so the client shows/hides them without a save
		 * round-trip (#375/#377).
		 *
		 * **THE CONTRACT FORK — pick ONE model per provider (#375):**
		 *
		 * 1. **"I need my own key(s)."** Declare them here, keyed by a field id
		 *    UNIQUE across every OTHER registered provider — the option namespace
		 *    (`woodev_location_*`) is SHARED by the whole fleet, so a collision
		 *    with another provider's field id is a real bug, not a private
		 *    concern; `Location_Provider_Registry` detects one and keeps the
		 *    first registration, reporting the rest via `_doing_it_wrong()`.
		 *    Read the value back through the SAME "raw option, never the
		 *    settings handler" discipline {@see self::is_configured()} documents
		 *    below — a provider whose fields are always registered can safely
		 *    use `Location_Settings::get_value()` too, but a provider that might
		 *    later drop to zero fields (model 2) cannot, so the raw-option read
		 *    is the one discipline that is correct under BOTH models and never
		 *    needs to change if a provider migrates between them.
		 * 2. **"My credentials belong to something bigger than a location
		 *    lookup."** This is the CARRIER case (#375's own example: CDEK's
		 *    Client ID/Secret authenticate every CDEK API call, not only its
		 *    location dictionary) — return `[]` here (the {@see Abstract_Location_Provider}
		 *    default; no override needed) and read the credentials from
		 *    wherever the plugin's OWN carrier-wide settings actually live
		 *    (typically a {@see \Woodev\Framework\Shipping\Shipping_Integration}
		 *    subclass — see {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_integration_option()}
		 *    for the accessor, which already falls back to a raw option read
		 *    when no integration handler exists yet). Declaring zero fields
		 *    here means {@see self::is_configured()}'s default (derived from
		 *    `get_settings_fields()`) would dishonestly report `true` — model 2
		 *    MUST override {@see self::is_configured()} to check the real
		 *    external location. {@see \Woodev_Test_Cdek_Location_Provider} (the
		 *    rig's fixture) is the reference implementation of this model.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Documented the two-model contract fork and the
		 *              now-shared field-id namespace (#375/#377).
		 *
		 * @return array<string, array<string, mixed>> Settings field definitions keyed by field id.
		 */
		public function get_settings_fields(): array;

		/**
		 * Whether this provider currently has everything it needs to operate
		 * (Task 6/7 contract) — e.g. an API token actually CONFIGURED, not
		 * merely declared as a settings field by {@see self::get_settings_fields()}.
		 *
		 * This is the provider's own HONEST answer about its own credentials —
		 * the framework never second-guesses it. {@see Location_Service::is_active()}
		 * and the D15 provider-fallback chain
		 * ({@see Location_Service::provider_for_level()}) both gate on this:
		 * an unconfigured provider is treated exactly like an unregistered one
		 * for every purpose downstream of those two call sites.
		 *
		 * @see Abstract_Location_Provider::is_configured() for the honest
		 *      default derived from {@see self::get_settings_fields()} — a
		 *      provider with a REQUIRED field (e.g. Task 7's DaData token)
		 *      MUST override this to check the actual stored value; the
		 *      default can only see the field's SHAPE, never whether a value
		 *      was actually saved. A provider following model 2 of
		 *      {@see self::get_settings_fields()}'s own contract-fork docblock
		 *      (zero declared fields, credentials live elsewhere) MUST ALSO
		 *      override this — the default would otherwise see zero required
		 *      fields and dishonestly report `true`.
		 *
		 * **RAW-OPTION DISCIPLINE (#375/#377).** This method is called on a
		 * provider even while it is NOT the active one — the D15 fallback
		 * chain ({@see Location_Service::resolve_provider_for_level()}) checks
		 * `is_configured()` on the bundled default provider regardless of what
		 * is currently active, and {@see Location_Provider_Registry::register_settings()}
		 * now computes a `show_if` visibility list from EVERY registered
		 * provider's answer too. A provider must therefore NEVER resolve its
		 * own credentials by reading through `Location_Settings::get_value()`
		 * (or the equivalent for wherever a model-2 provider's credentials
		 * live) — a settings handler only has a stored VALUE for a field that
		 * IS registered on it right now, and `get_value()` throws
		 * `Setting … does not exist` the instant that is not the case. Read
		 * the RAW stored option (`get_option()` for model 1;
		 * {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_integration_option()}'s
		 * raw-option fallback for model 2) instead — see
		 * {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::token()}'s
		 * own docblock for the canonical model-1 example and
		 * {@see \Woodev_Test_Cdek_Location_Provider::is_configured()} for model 2.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Documented the raw-option discipline (#375/#377) — this
		 *              method is now ALSO consulted for every registered (not
		 *              only the active) provider when building the settings
		 *              surface's `show_if` conditions.
		 *
		 * @return bool
		 */
		public function is_configured(): bool;

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
		 * **EMPTY VS. FAILED — THE #405 CONTRACT.** An empty array means the request
		 * COMPLETED and genuinely found nothing: a blank/too-short query, a provider
		 * this instance reports {@see self::is_configured()} `false` for, or a query
		 * the upstream source answered with zero matches. It must NEVER be returned
		 * for a request that could not be made at all — wrong credentials, a network
		 * failure, a malformed upstream payload. THAT case is signalled by throwing
		 * (typically {@see Location_Provider_Exception}, or a lower-level failure —
		 * e.g. `\Woodev_API_Exception` — left to propagate); `Location_Controller`
		 * catches any `\Throwable` here and answers a DISTINCT response (502,
		 * "источник подсказок недоступен") from its ordinary 200+empty degradation,
		 * so a shopkeeper testing keys and a customer at checkout can both tell "no
		 * matches" from "the provider could not answer" instead of both reading as
		 * silence. {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::suggest()}
		 * and {@see \Woodev_Test_Cdek_Location_Provider::suggest()} are the reference
		 * implementations of this split.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Documented the throw-on-failure contract (#405) — a provider
		 *              built before this MUST be revisited: swallowing every
		 *              failure into `[]`, this method's ENTIRE previous contract,
		 *              is exactly the bug #405 closes.
		 *
		 * @param string         $query Free-text search term, as typed so far.
		 * @param Location_Scope $scope Lookup scope.
		 *
		 * @return Location_Record[] Zero or more matches, contract-shaped (spec D12);
		 *                           the provider's own payload rides along opaque
		 *                           under each record's `raw`.
		 *
		 * @throws \Throwable When the request itself could not be completed — see
		 *                    the EMPTY VS. FAILED section above.
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


		/**
		 * By-key resolution — OPTIONAL, only when {@see self::get_capabilities()}
		 * contains {@see self::CAPABILITY_RESOLVE_KEY}.
		 *
		 * Given a {@see Locality_Key} previously produced by this SAME provider (e.g.
		 * carried on a stored {@see Location_Record}), returns the provider's CURRENT
		 * record for it — re-fetched, not cached — or `null` when the provider no
		 * longer recognises the key at all (popular-settlements spec D4).
		 *
		 * **`null` means "asked and told no", not "could not ask".** A request that
		 * could not be COMPLETED — wrong credentials, a network failure, a malformed
		 * upstream payload — MUST throw rather than return `null`, exactly the
		 * "EMPTY VS. FAILED" discipline {@see self::suggest()} documents (#405): a
		 * caller re-verifying a stored record (spec D5/D6) needs to tell "the provider
		 * confirms this locality is gone, delete the row" apart from "the provider
		 * could not be reached this time, try again later" — collapsing the two would
		 * turn a transient outage into silent data loss on precisely the most-ordered
		 * settlements, the opposite of what the freshness clock exists to prevent.
		 *
		 * No `Location_Scope` parameter: `$key`'s own namespaced native id already
		 * identifies one exact locality for this provider — the same `provider_id`
		 * that produced the key can always look it back up without a country/level
		 * hint, and the returned record carries its own `country`/`level` (a caller
		 * needing to confirm the level did not change compares the returned record's
		 * `level()` against what it already had).
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A namespaced locality key ({@see Locality_Key}) this
		 *                    SAME provider previously produced.
		 *
		 * @return Location_Record|null The provider's current record for `$key`, or
		 *                              null when the provider no longer recognises it.
		 *
		 * @throws \InvalidArgumentException When `$key` is malformed, or namespaced to
		 *                                    a different provider (see
		 *                                    {@see Locality_Key::parse()}).
		 * @throws \BadMethodCallException When this provider does not declare
		 *                                  {@see self::CAPABILITY_RESOLVE_KEY}.
		 * @throws \Throwable When the request itself could not be completed — see the
		 *                    "null means asked and told no" section above.
		 */
		public function resolve_key( string $key ): ?Location_Record;
	}

endif;
