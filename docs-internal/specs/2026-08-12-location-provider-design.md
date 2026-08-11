# Location Provider layer — design spec

> Status: **approved in brainstorm, pending operator review of this write-up**
> Session: s67 (12.08.2026). Participants: operator + agent. Brainstorm brief (facts only):
> `research/2026-08-11-locality-field-brainstorm-brief.md`.
> Cards: **#273** (who owns «Населённый пункт»), **#127** (SP-4 DaData suggestions + normalization),
> **#159** (points query addressed by parameters, not a locality name). The three are one problem
> seen from three sides; this design answers all three.

## 1. Problem

Several carrier plugins (СДЭК, Яндекс, Почта; next: OZON) each provide and consume locality data in
their own format, but the checkout has ONE set of location fields (Region / Locality / Address /
Postcode). Today the framework treats locality as an opaque string, every plugin fights for the
fields ("last registration wins", detected but not resolved), and each carrier holds its own
incompatible identity dictionary (`city_code` / `geo_id` / ФИАС / postal index). The operator's
current production answer — "СДЭК is the main plugin, others adapt" — is a crutch by his own
assessment.

**Core decision: a single entry point.** One store-level location data provider feeds all location
fields; every plugin translates the provider's neutral record into its carrier's identity through a
mandatory adapter.

## 2. Decisions made in the brainstorm (with rationale)

| # | Decision | Alternative rejected, why |
|---|---|---|
| D1 | Own contract, **mirroring the shape** of WC's Address Autocomplete Provider (id / countries / search / select + store-level provider choice), not built on it | WC's machinery hosts search only on `address_1`, returns flat strings (identity destroyed), clears fields it did not fill, has no cascade and no region/city scoping (`address-autocomplete.js`, verified on trunk) |
| D2 | WC's own autocomplete is **suppressed per country**: our countries → our cascade owns the fields and WC stands down; other countries → our layer stands down entirely and WC/Google serves them if enabled | Global kill would degrade non-CIS countries for stores that sell there; WC's own arbitration is already per-country (`canSearch(country)` walk), we align with its semantics |
| D3 | Framework **bundles a DaData provider** as the default implementation ("battery included"), hidden until a plugin requests the location-provider service. Supersedes the #127 note "DaData endpoints/tokens live in the plugin" | Three byte-identical copies of the DaData stack across the operator's plugins are exactly the duplication the framework exists to remove. The contract stays neutral; DaData is just the default implementation. Domain = carrier & tariffs; a settlement registry is shared delivery infrastructure |
| D4 | Provider tokens/keys are **store settings, held server-side**; the client talks only to the framework REST seam | The DaData token is public-ish, but there is no reason to expose it; also keeps the client provider-agnostic |
| D5 | Primary key = **`provider_id:native_id`** (namespace always attached), record travels whole | A bare universal id does not exist across registries; a bare ФИАС read under a different active provider would be misinterpreted as a valid foreign key. Prefixed keys make stale entries MISS — the safe outcome (cf. gotcha `an-empty-domain-key-is-not-a-key`) |
| D6 | One **neutral client widget** (WC-style progressive enhancement + select2 renderers), cascade engine is ours (§8), `suggestions-js` not used | The hflabs cascade (`constraints` / `shareWithParent`) only links its own DaData-backed text inputs; it cannot drive select2 modes or non-DaData providers, so our cascade engine is needed in every branch — keeping suggestions-js would mean maintaining two cascade engines forever. hflabs/suggestions-js is alive (v26.3.0, checked 12.08.2026) but not needed |
| D7 | Field **presentation mode is a store setting gated by provider capabilities**: text+typeahead always; "related list" (full list) and AJAX-select2 only when the provider has `list` | The operator's three СДЭК modes are three renderers over one data source, not three architectures. DaData cannot enumerate (query-driven API only); the CDEK dictionary can (`/location/cities?region_code=`) |
| D8 | Record is persisted **at selection time by a framework AJAX call**, which then triggers `update_checkout` itself | WC does not save the address until every required text field is filled and the gate is client-side (gotcha `wc-does-not-save-the-address-until-every-required-text-field-is-filled`); sites disable unneeded fields, so `updated_checkout` may never arrive. The direct trigger bypasses only WC's own field listeners' gate — the operator's plugins already do this after `set_customer_location`, which prevents stale shipping rates |
| D9 | Adapter resolution is **lazy, server-side, at rate/points calculation**, cached in session per `(locality_key, plugin)` | Eager resolve on select would add a per-carrier client protocol; freshness is guaranteed by D8's explicit `update_checkout`. Cache misses on provider switch or locality change by construction (prefixed keys) |
| D10 | Customer location store is **dual** (guest → `WC()->session`, logged-in → user meta, migrate on login) — the `WC_Edostavka_Customer_Location_Data` mechanics generalized into the framework | `WC()->customer` cannot hold custom fields; native fields persist only the locality NAME. Session-only storage would force re-guessing identity from a bare string («Октябрьский» ambiguity) for returning customers. NB: #178's "no dual store" verdict was about the pickup-selection map (stays session-only), not this record |
| D11 | Default locality: store policy = **fixed locality / geo-IP (`locate` capability) / off**; stored in the same slot flagged `implicit` | Provider supplies the mechanism, the store decides the policy. Implicit records never block a real customer choice and are not treated as the customer's answer |
| D12 | `suggest` returns fields **defined by our contract**, provider maps its payload into them; raw provider payload rides along as an opaque extra | Operator's explicit requirement. Adapters and cascade must not depend on any provider's dictionary. The raw extra lets e.g. the СДЭК adapter under the СДЭК provider take `city_code` with zero extra requests |
| D13 | Postcode is a **derived, write-only field** | The operator's own pipeline: changing postcode clears nothing and has no dependents; Почта's adapter reads the index from the RECORD, not from the field |
| D14 | The existing `Address_Normalizer` seam (zero call sites) is **superseded** by this contract; the registered-but-never-enqueued DaData assets are removed | Clean-break policy; the seam's `suggest()`/`normalize()` shape is absorbed into the provider contract |

## 3. Terminology (fixed)

- **Location provider** — a source of locality/address data (DaData, СДЭК dictionary, future
  others). One active per store. Framework-side, server-side.
- **Location record** — the neutral, contract-shaped result of a provider lookup. The only
  currency of the layer.
- **Locality key** — `provider_id:native_id`, or `provider_id:<derived>` when the provider has no
  native id (derived deterministically from normalized components by ONE shared framework helper).
- **Adapter** — per-plugin translator: location record → carrier identity (`city_code`, `geo_id`,
  postal codes…). Mandatory for every participating plugin, including the one that brought the
  active provider.
- **Level** — granularity of a record: `region` | `settlement` | `address`.

## 4. Architecture

### 4.1 Provider contract (PHP, framework)

A provider declares:

- `id`, `name` (unique; shown in the store-level setting).
- `countries` — static ISO-3166 alpha-2 list. Static so arbitration can happen server-side as well
  as client-side (WC's `canSearch` is JS-only and thus invisible to PHP).
- Capabilities:
  - `suggest( query, scope )` — **required.** Query-driven lookup. `scope` = country + level
    + optional parent constraint (a locality key or its components: "settlements within region X",
    "addresses within settlement Y").
  - `list( scope )` — optional. Enumeration for the "related list" mode (e.g. CDEK
    `/location/regions`, `/location/cities?region_code=`).
  - `locate( ip )` — optional. Geo-IP → record (DaData `iplocate/address`).
  - `normalize( free_form, scope )` — optional. Free-form address → record (DaData `clean`,
    Почта normalization use case; absorbs the old `Address_Normalizer::normalize()`).
- Store-level provider settings (e.g. the DaData token) — declared by the provider, rendered on
  the shared settings surface, stored server-side.

Registration: the framework bundles the DaData provider; a plugin may register its own (first
candidate: СДЭК). The service activates only after at least one plugin declares "I need a location
provider". The store setting then offers every registered provider; plugins that bring no provider
consume whichever is active.

### 4.2 Location record (contract shape, D12)

Fields (all optional unless noted): `key` (required, prefixed), `provider_id` (required), `level`
(required), `country` (required, ISO), `region` {name, type}, `district` {name, type},
`settlement` {name, type}, address components (street {name, type}, house, block, flat) for
`address`-level records, `postcode`, `lat`/`lon`, `label` (display string), `raw` (opaque provider
payload, never inspected by the framework).

The record travels whole everywhere: session slot, adapters, pickup `[location][type]` map. An
adapter takes from the record whatever it can resolve by — ФИАС for СДЭК (`/location/cities?fias_guid=`),
name+region strings for Яндекс (`location/detect` — Yandex cannot look up by ФИАС), postcode or
normalization for Почта.

### 4.3 Adapter contract (PHP, per plugin)

- A shipping plugin participating in the layer MUST provide an adapter:
  `resolve( Location_Record ): ?carrier_identity` (opaque to the framework).
- The framework runs it lazily (D9) and caches by `(locality_key, plugin_id)` in the session.
  Cache read/write is framework-owned; the plugin only implements `resolve()`.
- Resolution failure (`null`) is a legitimate answer ("carrier does not serve this locality") and
  is cached too, respecting the empty-key discipline (a failed resolve is never stored as an
  ordinary value).
- Minimum for a not-yet-written plugin (OZON): one adapter + the "I need a location provider"
  declaration. No fields, no cascade, no UI work.

### 4.4 Client layer: fields, modes, cascade

One neutral widget set, provider-agnostic, talking only to framework REST:

- **Typeahead mode** (always available): WC-style progressive enhancement — the native `<input>`
  stays; we add `role="combobox"`, ARIA attributes and an adjacent suggestions listbox. No element
  replacement, so checkout re-renders don't break it.
- **Related-list mode** (requires `list`): region as a WC states select (via the existing §8
  `woocommerce_states` injection), locality as select2 fed with the full per-region list.
- **AJAX-select2 mode** (requires `suggest`): select2 with remote data, same REST seam.

The mode is a store setting; the framework offers only modes the active provider supports.

**Cascade** (the operator's pipeline, verbatim): Country → Region → Locality → Address →
Postcode(write-only).

- Locality suggestions are scoped by region when a region field exists and is filled; otherwise by
  country. Address suggestions are scoped by locality. Absent fields narrow the chain without
  breaking it (no region field → locality searches country-wide; no address field → chain ends at
  locality).
- Changing/clearing a parent clears its descendants (region → locality+address+postcode; locality →
  address+postcode; address → postcode; postcode → nothing). The engine is the existing §8 cascade
  with the s66 remembered-parent-value gate (gotcha
  `a-programmatic-parent-change-must-not-run-a-destructive-cascade`) — a no-op programmatic change
  must not destroy a child's value.
- **Backwards fill:** selecting an address-level record fills region/locality/postcode from the
  record's own components (one record, no second lookup); selecting a locality fills region and
  postcode the same way.
- The locality field is mandatory in the layer (every carrier needs it); address is optional
  (PVZ flows fill it from the chosen point; courier flows may require it — that stays the plugin's
  field-config decision, as today).

### 4.5 Lifecycle

1. Customer picks a suggestion → client POSTs the full record to framework REST.
2. Framework stores it in the customer-location store (D10) and responds; client fires
   `jQuery(document.body).trigger('update_checkout')` (D8).
3. WooCommerce recalculates shipping server-side; each plugin's rate/points code asks the framework
   for its resolved identity; the framework runs the adapter through the session cache (D9).
4. Points query (#159): `Point_Query` is addressed by the record/key; the plugin's `Point_Source`
   receives the record plus its own resolved identity — never a bare name string.
5. Pickup persistence: the `[location][type]` session map now keys by the locality key.
   `Selection_Scope::current_locality()` returns it. Session KEYS stay plugin-owned and unchanged
   (installed-site contract); the value shape inside is internal → clean-break.

### 4.6 Default locality (D11)

Store policy: `fixed` (merchant picks a locality in admin via the active provider's search) /
`geoip` (provider `locate`, shown only when supported) / `off`. Resolved lazily on first need
(cart shipping calculator, checkout render), stored in the same slot flagged `implicit`. A real
customer selection overwrites it and drops the flag. Implicit records participate in rate
calculation but never suppress "please choose your locality" prompts.

### 4.7 Coexistence & degradation

- **WC Address Autocomplete** (option `woocommerce_address_autocomplete_enabled` + providers via
  `woocommerce_address_providers`, since 9.9.0): suppressed per-country (D2). Mechanics — decided at
  plan time, two candidates measured in s67: (1) client-side wrap of the WC provider registry
  entries (their arbitration reads the registry live on every country change; entries are frozen
  but replaceable); (2) when the store's selling countries ⊆ our provider's countries, the
  documented server-side kill `woocommerce_address_providers → []` late-priority. Both cover
  classic and block checkouts (same `AddressProviderController`).
- **No provider active / no token configured:** fields behave natively; carrier plugins keep
  working without suggestions (as today). The layer is additive.
- **Unsupported country selected:** our widgets detach, cascade stands down, native WC behavior
  (and WC's own autocomplete, if the merchant enabled it) takes over. On return to a supported
  country the layer re-attaches.
- **Block checkout:** out of scope (SP-11 unbuilt). The contract is server-side and REST-based, so
  the future blocks adapter consumes it unchanged.

## 5. What this replaces / removes (clean-break)

- `Address_Normalizer` interface, `Null_Address_Normalizer`, `Shipping_Plugin::get_address_normalizer()`
  — superseded (zero call sites, verified s66).
- Registered-but-never-enqueued `jquery-suggestions` / `woodev-dadata-suggestions` assets
  (`class-plugin.php:514-515`) — removed.
- §8's plugin-supplied city select2 (option-list source) — remains for non-location fields; the
  location fields gain the provider-backed source kind. `resolveLocality()`'s opaque-string read is
  replaced by the framework record store.
- Native-field conflict guard (`guard_native_field_conflicts`) stays as a tripwire, but the fight
  it detects disappears by construction: plugins stop bringing their own location fields (#273).

## 6. Constraints honored

- Framework = mechanism + contract + hooks; carrier domain (tariffs, PVZ logic, a specific
  registry's quirks) stays in plugins. The bundled DaData provider is an implementation of a
  neutral contract, not a framework dependency on DaData (D3).
- Installed-site data contracts untouched: no existing option/session/meta key changes. New keys
  are framework-owned and new. Pickup session keys unchanged (value shape internal).
- RU-only registries are not assumed by the contract: ФИАС appears only inside records/raw payloads
  and adapters, never as a contract requirement. Countries are declared per provider (D2).
- Extension hooks are left even without consumers (recorded preference) — the plan must include
  filters at: provider registration, record post-processing, adapter cache TTL/invalidation,
  suppression policy, default-locality resolution.

## 7. Testing expectations (spec level)

- **Jest:** cascade matrix (field presence × parent change × programmatic vs user change ×
  backwards fill), widget attach/detach on country switch, suppression arbitration, select →
  persist → `update_checkout` trigger order. Real-jQuery paths for select2/event-world coverage
  (gotchas `jquery-trigger-change-fires-no-native-event`, s66 lessons 11–14).
- **PHP unit:** record shape validation, key derivation helper determinism, provider registration
  + store setting, adapter cache hit/miss/failure semantics, dual-store read/write/migration,
  per-country server arbitration.
- **Rig:** the operator's three pipeline scenarios end-to-end (region-first, address-first,
  no-region-field) on `/classic-checkout/` with live DaData; provider switch invalidating caches;
  default-locality policies; coexistence with WC autocomplete toggled on.

## 8. Out of scope

- SP-11 block-checkout adapter.
- Migrating the three production plugins onto the layer (per-plugin migration docs own that).
- Carrier-side tariff/points logic changes beyond consuming the resolved identity.
- Admin order-edit address tooling.

## Related

- Brief: `research/2026-08-11-locality-field-brainstorm-brief.md`
- Cards: #273, #127, #159; adjacent #270, #274.
- Program map: `specs/2026-06-25-shipping-module-decisions.md` (SP-3/SP-4).
- Gotchas: `an-empty-domain-key-is-not-a-key`,
  `wc-does-not-save-the-address-until-every-required-text-field-is-filled`,
  `a-programmatic-parent-change-must-not-run-a-destructive-cascade`,
  `checkout-field-takeover-woocommerce-states`, `built-on-both-sides-with-no-caller-in-the-middle`.
