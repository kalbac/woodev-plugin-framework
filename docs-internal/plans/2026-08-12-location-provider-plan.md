# Location Provider Layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the store-level Location Provider layer: one neutral provider (DaData bundled) feeds
all checkout location fields (Region / Locality / Address / Postcode) through a contract-shaped
record with a namespaced primary key; every shipping plugin translates the record into its
carrier's identity via a mandatory adapter, lazily, with session caching.

**Architecture:** Server-side provider behind the framework REST seam (`woodev/v1/location/*`);
neutral `Location_Record` with `provider_id:native_id` keys; dual customer-location store
(session/user-meta); lazy per-plugin adapter resolution cached by `(locality_key, plugin_id)`;
one provider-agnostic client layer (WC-style typeahead + select2 renderers) driving the existing
§8 cascade; per-country arbitration with WC's own Address Autocomplete. Spec (authoritative, 14
decisions D1–D14): `docs-internal/specs/2026-08-12-location-provider-design.md`.

**Tech Stack:** PHP 7.4+ (`Woodev\Framework\Shipping\Location\*`), WP REST (`woodev/v1`),
hand-written frontend JS (vanilla + jQuery classic glue, no webpack), `@wordpress/scripts` jest,
Brain Monkey + Mockery unit tests, DaData suggestions API (server-side).

---

## Conventions for every task

- New PHP: namespaced, `Snake_Case` classes, `snake_case` methods, typed params/returns, docblocks
  (`@since` = current `Woodev_Plugin::VERSION` per CLAUDE.md), short arrays `[]`, Yoda, `??`.
  Guard each class file with `if ( ! class_exists(...) ) :`.
- **Serena is mandatory for reading/navigating PHP** (`activate_project('woodev_framework')`
  first); use built-in `Edit` for edits (Serena CRLF-flip gotcha). Every subagent brief that
  touches PHP MUST repeat this rule.
- After adding/moving any PHP class: regenerate the class-map (`php bin/generate-class-map.php`).
- Unit tests: `./vendor/bin/phpunit --testsuite unit` — run the FULL suite after wiring into a
  shared path (s40 lesson). Jest: `npm run test:js -- --roots "<rootDir>/tests/js"` — NEVER bare
  `npx jest`. PHPStan/phpcs are CI-authoritative (PHPStan segfaults locally on Windows).
- JS event code: bind in BOTH event worlds where select2/jQuery produce events — jQuery
  `.trigger('change')` creates NO native event (gotcha `jquery-trigger-change-fires-no-native-event`);
  at least one jest test per producer path must run through the REAL jQuery (devDependency).
- The `checkout-field-classic.js` jest harness needs **three** timer generations before assertions
  (jQuery 3 async ready → `.then()` → takeover `setTimeout(0)`).
- UI strings user-facing on checkout/admin: Russian, text domain `woodev-plugin-framework`.
- Commit after each task (Conventional Commits). Long messages → `git commit -F <file>`.
- **Implementers are explicitly licensed to contradict this plan when the code disagrees** —
  report the contradiction, do not silently follow the plan (s52 rule).

## PR blocks (merge cadence)

| Block | Tasks | Ships |
|---|---|---|
| PR-A | 1–6 | PHP contract core: record, key, scope, provider contract, registry+activation+settings, customer store, adapter cache, service façade |
| PR-B | 7–9 | DaData provider + REST seam + checkout config exposure |
| PR-C | 10–12 | Client: typeahead widget, cascade integration, WC-autocomplete arbitration |
| PR-D | 13–16 | select2 modes, default locality, pickup integration (#159), clean-break removals + docs |

Each block leaves `main` green and the layer inert-but-testable until PR-C wires the client.
Non-UI blocks self-merge on green CI; PR-C and PR-D end with rig verification for the operator.

## File Structure

**PHP core (`woodev/shipping-method/location/`, namespace `Woodev\Framework\Shipping\Location`):**
- `class-location-record.php` — NEW: immutable value object; `from_array()` validation, `to_array()`.
- `class-locality-key.php` — NEW: static key helper (compose / derive / parse).
- `class-location-scope.php` — NEW: lookup scope value object (country, level, parent constraint).
- `interface-location-provider.php` — NEW: provider contract.
- `abstract-location-provider.php` — NEW: capability defaults (optional methods throw `\BadMethodCallException`).
- `class-location-provider-registry.php` — NEW: registration filter, activation gate, active-provider resolution.
- `class-customer-location-store.php` — NEW: dual store (session / user meta) + `implicit` flag + login migration.
- `interface-location-adapter.php` — NEW: `resolve( Location_Record ): mixed|null`.
- `class-location-resolution-cache.php` — NEW: session cache per `(locality_key, plugin_id)`, caches failures.
- `class-location-service.php` — NEW: façade (active provider, customer record get/set, `resolve_for( $plugin )`, default-locality policy).
- `providers/class-dadata-api-client.php` — NEW: server-side DaData HTTP client (framework API layer).
- `providers/class-dadata-provider.php` — NEW: DaData implementation (suggest/locate; normalize when secret configured).

**REST:**
- `woodev/shipping-method/rest-api/class-location-controller.php` — NEW: `woodev/v1/location/(suggest|select)` (+`locate` in Task 14), reuses `trait-rest-rate-limit.php`.

**Integration points (MODIFY):**
- `woodev/shipping-method/class-shipping-plugin.php` — `needs_location_provider(): bool` (default `false`), `get_location_adapter(): ?Location_Adapter` (default `null`); REMOVE `get_address_normalizer()` (Task 16).
- `woodev/shipping-method/checkout/class-field.php` + `class-checkout-fields.php` — new source kind `location` + `location_level` descriptor key.
- `woodev/shipping-method/checkout/class-checkout-config.php` — expose the location config block (endpoints, nonce, active-provider countries, field modes, current record key).
- `woodev/shipping-method/checkout/class-checkout-handler.php` — enqueue new JS when the layer is active.
- `woodev/shipping-method/pickup/interface-selection-scope.php` consumers + `class-point-query.php`, `interface-point-source.php` — record/key addressing (Task 15).
- Settings registry (SP-1 surface): provider choice, DaData token/secret, field mode, default-locality policy — follow the existing registry pattern (study with Serena before writing; the SP-1 settings page + `woodev/v1/settings` REST already exist).

**Frontend JS (`woodev/shipping-method/assets/js/frontend/`):**
- `location-typeahead.js` — NEW: neutral combobox (progressive enhancement, WC-style).
- `location-cascade.js` — NEW: field graph wiring (scoping, dependent clearing via the §8 store gate, backwards fill, postcode write-only, per-country attach/detach, WC-autocomplete suppression).
- `location-select-modes.js` — NEW (Task 13): related-list + AJAX select2 renderers.

**Tests:**
- `tests/unit/Shipping/Location/*Test.php` — NEW.
- `tests/js/location-typeahead.test.js`, `tests/js/location-cascade.test.js`, `tests/js/location-select-modes.test.js` — NEW.
- `tests/_fixtures/woodev-test-shipping-method/` — MODIFY: declare `needs_location_provider()`, a fake `list`-capable provider and a fake adapter for integration/rig testing.

---

## Task 1: `Location_Record` + `Locality_Key`

**Files:**
- Create: `woodev/shipping-method/location/class-location-record.php`
- Create: `woodev/shipping-method/location/class-locality-key.php`
- Test: `tests/unit/Shipping/Location/LocationRecordTest.php`, `tests/unit/Shipping/Location/LocalityKeyTest.php`

- [ ] **Step 1: Write failing tests.**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Locality_Key;
use Woodev\Tests\Unit\TestCase;

class LocalityKeyTest extends TestCase {

	public function test_compose_prefixes_provider_id(): void {
		$this->assertSame( 'dadata:abc-123', Locality_Key::compose( 'dadata', 'abc-123' ) );
	}

	public function test_parse_splits_on_first_colon_only(): void {
		$this->assertSame( [ 'dadata', 'a:b' ], Locality_Key::parse( 'dadata:a:b' ) );
	}

	public function test_derive_is_deterministic_and_prefixed(): void {
		$components = [ 'country' => 'RU', 'region' => 'Тюменская', 'settlement' => 'Октябрьский', 'type' => 'пгт' ];
		$a = Locality_Key::derive( 'noid', $components );
		$b = Locality_Key::derive( 'noid', [ 'type' => 'пгт', 'settlement' => 'Октябрьский', 'region' => 'Тюменская', 'country' => 'RU' ] );

		$this->assertSame( $a, $b ); // key order must not matter
		$this->assertStringStartsWith( 'noid:', $a );
	}

	public function test_empty_native_id_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Locality_Key::compose( 'dadata', '' ); // an empty domain key is not a key
	}
}

class LocationRecordTest extends TestCase {

	public function test_from_array_requires_key_provider_level_country(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'level' => 'settlement', 'country' => 'RU' ] ); // no key/provider
	}

	public function test_from_array_round_trips_and_keeps_raw_opaque(): void {
		$data = [
			'key'         => 'dadata:fias-1',
			'provider_id' => 'dadata',
			'level'       => 'settlement',
			'country'     => 'RU',
			'region'      => [ 'name' => 'Москва', 'type' => 'г' ],
			'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
			'postcode'    => '101000',
			'lat'         => 55.75,
			'lon'         => 37.61,
			'label'       => 'г Москва',
			'raw'         => [ 'city_kladr_id' => '7700000000000' ],
		];
		$record = Location_Record::from_array( $data );

		$this->assertSame( 'dadata:fias-1', $record->key() );
		$this->assertSame( 'settlement', $record->level() );
		$this->assertSame( [ 'city_kladr_id' => '7700000000000' ], $record->raw() );
		$this->assertSame( $data, array_intersect_key( $record->to_array(), $data ) );
	}

	public function test_invalid_level_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Location_Record::from_array( [ 'key' => 'x:1', 'provider_id' => 'x', 'level' => 'galaxy', 'country' => 'RU' ] );
	}
}
```

- [ ] **Step 2: Run tests, verify they FAIL** (classes do not exist).
- [ ] **Step 3: Implement.** `Locality_Key`: `compose()` throws on empty provider/native id;
  `derive( $provider_id, array $components )` = `compose( $provider_id, substr( sha1( canonical ) , 0, 20 ) )`
  where `canonical` = ksort'ed, lower-cased, trimmed non-empty components joined `|` — ONE shared
  helper so every provider derives identically (spec D5). `parse()` splits on the FIRST `:`.
  `Location_Record`: private constructor + `from_array()` validating `key`, `provider_id`,
  `level ∈ {region, settlement, address}`, `country` (2-letter, uppercased); everything else
  optional; `raw` stored untouched, never inspected. Accessors + `to_array()`.
- [ ] **Step 4: Tests green. Regenerate class-map. Commit** `feat(location): neutral location record and namespaced locality key`.

## Task 2: `Location_Scope` + provider contract

**Files:**
- Create: `woodev/shipping-method/location/class-location-scope.php`
- Create: `woodev/shipping-method/location/interface-location-provider.php`
- Create: `woodev/shipping-method/location/abstract-location-provider.php`
- Test: `tests/unit/Shipping/Location/LocationScopeTest.php`, `tests/unit/Shipping/Location/AbstractLocationProviderTest.php`

- [ ] **Step 1: Write failing tests** — scope refuses an unknown level; parent constraint accepts a
  `Location_Record` OR raw components; abstract provider: `capabilities()` reports only implemented
  optional methods; calling an unimplemented optional method throws `\BadMethodCallException`.
- [ ] **Step 2–3: Implement.** Interface:

```php
interface Location_Provider {
	public function get_id(): string;
	public function get_name(): string;
	/** @return string[] ISO-3166 alpha-2, static list (spec D2: server-side arbitration needs PHP visibility) */
	public function get_countries(): array;
	/** @return string[] subset of [ 'suggest', 'list', 'locate', 'normalize' ] */
	public function get_capabilities(): array;
	/** @return Location_Record[] */
	public function suggest( string $query, Location_Scope $scope ): array;
	/** @return Location_Record[] full enumeration — only when 'list' capability declared */
	public function list_localities( Location_Scope $scope ): array;
	public function locate( string $ip ): ?Location_Record;
	public function normalize( string $free_form, Location_Scope $scope ): ?Location_Record;
}
```

`Abstract_Location_Provider` implements the three optional methods with
`throw new \BadMethodCallException` and computes `get_capabilities()` from overridden methods
(`ReflectionMethod::getDeclaringClass()`), so a provider cannot claim a capability it did not
implement.
- [ ] **Step 4: Tests green. Class-map. Commit** `feat(location): provider contract with capability discovery`.

## Task 3: Provider registry, activation gate, store settings

**Files:**
- Create: `woodev/shipping-method/location/class-location-provider-registry.php`
- Modify: `woodev/shipping-method/class-shipping-plugin.php` (add `needs_location_provider(): bool { return false; }`)
- Modify: settings registration (SP-1 registry — study the existing pattern with Serena first)
- Test: `tests/unit/Shipping/Location/LocationProviderRegistryTest.php`

- [ ] **Step 1: Write failing tests:**
  - registry inert (no settings registered, `get_active_provider()` null) when NO registered
    shipping plugin returns `needs_location_provider() === true`;
  - with the gate open: bundled DaData provider present; a plugin-registered provider (via filter
    `woodev_location_providers`) appears alongside; duplicate ids → `_doing_it_wrong` + first wins;
  - active provider = store setting value, falling back to `dadata`;
  - a filter-returned object not implementing `Location_Provider` is rejected and logged.
- [ ] **Step 2–3: Implement.** Registration on `init` (NEVER `is_checkout()`-gated — REST requests
  need the registry; §8 lesson). Settings registered on the shared SP-1 surface: provider select
  (options = registered providers), plus each provider's own declared settings (Task 7 adds
  DaData's). Leave hooks: `woodev_location_providers` (filter), `woodev_location_active_provider`
  (filter over the resolved instance).
- [ ] **Step 4: Full unit suite green. Class-map. Commit** `feat(location): provider registry with activation gate and store setting`.

## Task 4: `Customer_Location_Store` (dual store, spec D10)

**Files:**
- Create: `woodev/shipping-method/location/class-customer-location-store.php`
- Test: `tests/unit/Shipping/Location/CustomerLocationStoreTest.php`

- [ ] **Step 1: Write failing tests** (Brain Monkey; mock `WC()->session`, `is_user_logged_in`,
  `get_user_meta`/`update_user_meta`):
  - guest: write→read round-trip via session key `woodev_customer_location`; no meta touched;
  - logged-in: write goes to BOTH user meta and session; read prefers session, falls back to meta;
  - `implicit` flag stored and reported; an explicit `set()` overwrites an implicit record and
    drops the flag; an implicit `set()` does NOT overwrite an explicit record;
  - `wp_login` migration: session record copied to user meta;
  - a record whose `key` is empty is refused on write AND read returns null (empty-key discipline);
  - guest write without an initialized WC session is a no-op returning `false`, never a fatal
    (gotcha `guest-session-write-needs-the-cart-cookie`).
- [ ] **Step 2–3: Implement.** Store the full `to_array()` + `implicit` bool + `saved_at`
  timestamp. NEW framework-owned keys only (installed-site contracts untouched).
- [ ] **Step 4: Tests green. Class-map. Commit** `feat(location): dual customer location store with implicit-default flag`.

## Task 5: Adapter contract + resolution cache (spec D9)

**Files:**
- Create: `woodev/shipping-method/location/interface-location-adapter.php`
- Create: `woodev/shipping-method/location/class-location-resolution-cache.php`
- Modify: `woodev/shipping-method/class-shipping-plugin.php` (add `get_location_adapter(): ?Location_Adapter { return null; }`)
- Test: `tests/unit/Shipping/Location/LocationResolutionCacheTest.php`

- [ ] **Step 1: Write failing tests:**
  - first `resolve_for( $plugin, $record )` calls the adapter once; second call same
    `(key, plugin)` returns cached value, adapter NOT called again (spy);
  - adapter returning `null` ("carrier does not serve") is cached as a distinct failure marker —
    re-read returns null WITHOUT re-calling, and the marker is not confusable with "never asked";
  - different locality key or different plugin id → separate cache slots;
  - adapter throwing → exception logged, treated as transient: NOT cached, next call retries;
  - a plugin with `needs_location_provider() === true` but `get_location_adapter() === null` →
    `_doing_it_wrong` (adapter is a REQUIRED obligation, spec §4.3).
- [ ] **Step 2–3: Implement.** Cache lives in `WC()->session` under one framework key:
  `[ locality_key ][ plugin_id ] => [ 'v' => mixed, 'ok' => bool ]`. Expose invalidation filter
  `woodev_location_resolution_cache_ttl` (leave the hook even without a consumer).
- [ ] **Step 4: Tests green. Class-map. Commit** `feat(location): mandatory adapter contract with lazy session-cached resolution`.

## Task 6: `Location_Service` façade

**Files:**
- Create: `woodev/shipping-method/location/class-location-service.php`
- Test: `tests/unit/Shipping/Location/LocationServiceTest.php`

- [ ] **Step 1: Write failing tests:** `is_active()` (gate + provider configured);
  `get_customer_record()` / `set_customer_record()` delegate to the store;
  `resolve_for( $plugin )` = current record → cache → adapter, null when no record;
  `is_country_supported( 'RU' )` consults the active provider's static list.
- [ ] **Step 2–3: Implement** as the single entry point other framework code uses (pickup layer,
  REST, checkout config). Wire instantiation into `Shipping_Plugin` bootstrap next to the existing
  handlers (study with Serena how `get_checkout_handler()` and peers are constructed and follow
  that shape).
- [ ] **Step 4: FULL unit suite green (shared-path wiring). Class-map. Commit** `feat(location): location service facade wired into shipping plugin`.

## Task 7: DaData provider (server-side)

**Files:**
- Create: `woodev/shipping-method/location/providers/class-dadata-api-client.php`
- Create: `woodev/shipping-method/location/providers/class-dadata-provider.php`
- Test: `tests/unit/Shipping/Location/DadataProviderTest.php`

- [ ] **Step 1: Write failing tests** (mock the HTTP layer, fixture JSON from real DaData
  responses — take payload shapes from `plugins-reference/woocommerce-edostavka/assets/js/frontend/fields-autocomplete.js`
  usage and the DaData docs, NOT from memory):
  - `suggest( 'Моск', scope(level=region, RU) )` maps to records: `level=region`,
    key = `dadata:<region_fias_id>`, region name/type filled, label = `value`;
  - `suggest` at `settlement` level scoped by a region record sends `locations` constraint +
    `restrict_value`, drops `fias_level 65` rows (planning-structure noise — same filter as the
    reference plugins), fills `postcode`, `lat`/`lon`, keeps full DaData `data` in `raw`;
  - `suggest` at `address` level scoped by a settlement record uses street→house bounds;
  - a suggestion with no FIAS id derives the key via `Locality_Key::derive()` (Task 1 helper);
  - `locate( '1.2.3.4' )` → `iplocate/address` → settlement record or null;
  - `get_capabilities()` contains `normalize` ONLY when the "clean" secret is configured
    (DaData clean API needs token+secret; capability presence follows configuration);
  - no token configured → provider reports itself unconfigured; `Location_Service::is_active()`
    false.
- [ ] **Step 2–3: Implement.** API client on the framework API layer
  (`Woodev_Abstract_API_JSON_Request/Response`; requests auto-logged via the standard action).
  Endpoints: `suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`, `iplocate/address`,
  `findById/address`; clean via `cleaner.dadata.ru/api/v1/clean/address` (token+secret) when
  configured. Level→bounds mapping inside the provider: `region` → `region…area`,
  `settlement` → `city…settlement`, `address` → `street…house`. Provider settings declared for the
  SP-1 surface: token (required), clean-secret (optional). `get_countries()`: DaData covers RU
  (+CIS suggestions are RU-registry-centric) — ship `[ 'RU' ]` and leave the filter
  `woodev_location_provider_countries` for stores that want to widen it.
- [ ] **Step 4: Tests green. Class-map. Commit** `feat(location): bundled server-side dadata provider`.

## Task 8: REST seam — `woodev/v1/location/suggest` + `/select`

**Files:**
- Create: `woodev/shipping-method/rest-api/class-location-controller.php`
- Modify: `woodev/shipping-method/rest-api/class-shipping-rest-api.php` (register the controller)
- Test: `tests/unit/Shipping/Location/LocationControllerTest.php` + integration test hitting the route WITHOUT rendering checkout

- [ ] **Step 1: Write failing tests:**
  - `GET /suggest?q=&level=&within=` → 400 on `q` shorter than 2 chars or over 128 (wc_clean +
    length cap, §8 hardening); `level` outside the enum → 400; unknown `within` key → treated as
    absent, NOT an error (stale client state must not brick the field);
  - happy path returns `{ suggestions: [ { key, label, level, record } ] }` with `label`
    escaped server-side; rate-limited via `trait-rest-rate-limit.php`; intentionally public-read
    (comment in code, as the §8 source controller does);
  - `POST /select` body = full record array → validated through `Location_Record::from_array()`
    (a malformed record → 400, nothing written), stored via the service as EXPLICIT;
  - `/select` when the layer is inactive → 404;
  - suggest proxies to the ACTIVE provider only — a request cannot name a provider (no
    client-chosen provider: the store setting decides).
- [ ] **Step 2–3: Implement.** Nonce-checked for `/select` (write), public-read for `/suggest`
  mirroring the §8 field-source controller's hardening. Response record = `to_array()` — the
  client must round-trip it back to `/select` unmodified.
- [ ] **Step 4: Unit + integration green. Class-map. Commit** `feat(location): REST suggest and select endpoints`.

## Task 9: Checkout config exposure + descriptor source kind

**Files:**
- Modify: `woodev/shipping-method/checkout/class-field.php` (fluent: `->source_location( string $level )`)
- Modify: `woodev/shipping-method/checkout/class-checkout-fields.php` (`normalize()`: `source_kind = 'location'`, `location_level`)
- Modify: `woodev/shipping-method/checkout/class-checkout-config.php` (config block)
- Modify: `woodev/shipping-method/checkout/class-checkout-handler.php` (enqueue Task-10/11 assets when active)
- Test: extend `tests/unit/Shipping/Checkout/CheckoutFieldsTest.php`, `CheckoutConfigTest.php`

- [ ] **Step 1: Write failing tests:** normalized descriptor carries
  `source_kind === 'location'` + `location_level`; config block contains
  `{ endpoints: { suggest, select }, nonce, countries: [...], mode, current: { key, level } | null, implicit: bool }`
  and NO token/secret leak (assert the serialized config contains neither); config `current` comes
  from the customer store.
- [ ] **Step 2–3: Implement.** The location block rides inside the existing §8 localized config
  (one config object, one enqueue path). Field presence stays the plugin's field-config decision —
  the framework maps whatever location-kind fields exist onto the cascade chain
  country → region → settlement → address (+postcode write-only), skipping absent links (spec §4.4).
- [ ] **Step 4: Full unit suite green. Commit** `feat(location): checkout descriptor source kind and client config block`.

## Task 10: `location-typeahead.js` — neutral combobox

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/location-typeahead.js`
- Test: `tests/js/location-typeahead.test.js`

- [ ] **Step 1: Write failing jest tests** (jsdom; module exposes a factory
  `attachTypeahead( input, { fetch, onSelect, minChars } )` returning `{ detach }`):
  - progressive enhancement: the native `<input>` element is NOT replaced; `role="combobox"`,
    `aria-autocomplete`, `aria-expanded` set on attach and fully removed on `detach()` (spec D6 —
    attach/detach must be clean because arbitration re-runs per country);
  - typing ≥2 chars debounces (250ms) then calls `fetch(query)` once; stale responses (an earlier
    fetch resolving after a later one) are discarded — use a generation counter, test with two
    out-of-order promise resolutions;
  - suggestion labels rendered via `textContent`, NEVER `innerHTML` (XSS; §8 rule);
  - keyboard: ↓/↑ move active item, Enter selects (calls `onSelect(item)` and closes), Esc closes;
    click outside closes;
  - empty results → listbox hidden, no "no results" chrome (blocked-control rule: no explanations).
- [ ] **Step 2: Run jest — FAIL.**
- [ ] **Step 3: Implement** vanilla (no jQuery inside this module — producers of jQuery events are
  handled by the cascade layer). Reuse the visual language of WC's implementation (combobox +
  absolute-positioned `<ul>` under the input) but our own CSS class namespace `woodev-location-*`.
- [ ] **Step 4: Jest green (`npm run test:js -- --roots "<rootDir>/tests/js"`). Commit** `feat(location): neutral typeahead combobox widget`.

## Task 11: `location-cascade.js` — field graph, persistence, backwards fill

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/location-cascade.js`
- Test: `tests/js/location-cascade.test.js`

- [ ] **Step 1: Write failing jest tests** (REAL jQuery for the event-producer paths — gotcha 11):
  - builds the chain from present fields only: fixture DOMs for {region+city+address+postcode},
    {city+address}, {city only}; suggestion scope for city = chosen region record key when the
    region link exists and is filled, else country;
  - user selects a settlement → `POST /select` with the FULL record (round-tripped untouched),
    then `jQuery(document.body).trigger('update_checkout')` — order asserted (persist resolves
    BEFORE the trigger; spec D8);
  - dependent clearing DOWNWARD only, with the §8 remembered-parent gate: a programmatic
    re-assignment of the SAME parent value must NOT clear children (mirror
    `a-programmatic-parent-change-must-not-run-a-destructive-cascade`); clearing region clears
    city+address+postcode; clearing address clears only postcode; editing postcode clears nothing
    (spec D13);
  - backwards fill: selecting an address-level record writes region/city/postcode from the
    record's own components — NO second lookup issued (assert fetch spy count);
  - both event worlds: children bound via delegated native listener AND jQuery `.on('change')`;
    double delivery is harmless by construction (test fires both for the same mutation, asserts
    single cascade application — idempotence via the remembered-value gate);
  - country switch to an unsupported country → every attached widget detached, fields left
    native, store state kept; switch back → re-attached with state intact.
- [ ] **Step 2: FAIL. Step 3: Implement** on top of the §8 store (`checkout-field-store.js`)
  rather than a parallel state world — the store already owns canonical values + cascade edges;
  this module adds location semantics (record objects, persist, backwards fill, attach/detach).
- [ ] **Step 4: Jest green — full suite, not just new files. Commit** `feat(location): cascade wiring with persistence and backwards fill`.

## Task 12: WC Address Autocomplete arbitration (spec D2)

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/location-cascade.js` (suppression module section)
- Modify: `woodev/shipping-method/checkout/class-checkout-handler.php` (server-side kill when applicable)
- Test: `tests/js/location-cascade.test.js` (suppression describe-block), `tests/unit/Shipping/Checkout/CheckoutHandlerTest.php`

- [ ] **Step 1: Write failing tests:**
  - **server:** when the layer is active AND every WC selling country ∈ provider countries →
    `woocommerce_address_providers` filtered to `[]` at `PHP_INT_MAX` (documented kill;
    covers both checkouts via `AddressProviderController`); otherwise the filter is NOT touched;
  - **client (mixed-country stores):** with a fake `window.wc.addressAutocomplete` registry
    (fixture mirroring `address-autocomplete-common.js`: `providers`, `serverProviders`,
    frozen provider objects), our wrap replaces each registry ENTRY with a delegating clone whose
    `canSearch( country )` returns `false` for OUR countries and delegates otherwise — WC's
    arbitration loop reads the registry live, so this is timing-safe; original provider object
    untouched (frozen);
  - our own widgets attach ONLY for supported countries (already covered in Task 11 — reference,
    don't duplicate).
- [ ] **Step 2: FAIL. Step 3: Implement.** The client wrap is fenced: if
  `window.wc.addressAutocomplete` is absent (option off / old WC) do nothing. Comment in code:
  this touches WC's public namespace but not a documented contract — re-verify on WC majors
  (add a gotcha file for this at implementation time).
- [ ] **Step 4: Green (js + unit). Commit** `feat(location): per-country arbitration with WC address autocomplete`.

## Task 13: select2 modes (related-list + AJAX) — spec D7

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/location-select-modes.js`
- Modify: settings surface (mode setting: gated options), `class-checkout-config.php` (mode in config)
- Modify: `tests/_fixtures/woodev-test-shipping-method/` (fixture provider WITH `list` capability — the bundled DaData provider has none; tests and the rig need a `list`-capable fake)
- Test: `tests/js/location-select-modes.test.js`, extend registry/settings unit tests

- [ ] **Step 1: Write failing tests:**
  - settings: mode options offered = f(active provider capabilities): DaData → typeahead only;
    fixture provider with `list` → + related-list + ajax-select2 (PHP unit on the settings
    definition);
  - related-list: region options injected through the EXISTING §8 `woocommerce_states` path
    (respect gotcha `checkout-field-takeover-woocommerce-states`: never write `[]`); city select2
    populated from `list_localities( scope( region ) )` via REST — add `GET /location/list` to the Task-8
    controller in this task (same hardening, `list` capability required, 404 otherwise);
  - ajax-select2: select2 remote data through `/location/suggest`; selection produces the same
    record → same `/select` persist path as Task 11 (assert the shared code path, not a copy);
  - select2 event world: selection via jQuery `.trigger('change')` still reaches the cascade
    (real-jQuery test — gotcha 11).
- [ ] **Step 2: FAIL. Step 3: Implement.** Renderers plug into the cascade's field abstraction:
  the cascade must not know WHICH renderer a field uses (mode = presentation, provider = data —
  spec D7 rationale).
- [ ] **Step 4: Green. Commit** `feat(location): related-list and ajax select2 field modes`.

## Task 14: Default locality (spec D11)

**Files:**
- Modify: `woodev/shipping-method/location/class-location-service.php` (+`resolve_default()`)
- Modify: settings surface (policy: `off` | `fixed` | `geoip`; fixed-locality picker via admin suggest)
- Modify: `woodev/shipping-method/rest-api/class-location-controller.php` (admin-only suggest context for the picker; `locate` support)
- Test: `tests/unit/Shipping/Location/LocationServiceDefaultTest.php`

- [ ] **Step 1: Write failing tests:**
  - policy `off` → `resolve_default()` null, nothing stored;
  - policy `fixed` → stored merchant record written to the customer store flagged `implicit`
    on first read when no record exists; an existing EXPLICIT record is never touched;
  - policy `geoip` → provider `locate( $ip )` called once, result stored implicit; `locate`
    failure/null → no store write, next request may retry (no failure caching here — geo-IP is
    transient); policy `geoip` offered in settings ONLY when the provider has `locate`;
  - resolution is lazy: triggered from `get_customer_record()` when empty (so cart/checkout both
    get it with zero extra wiring — spec §4.6), NOT on every request.
- [ ] **Step 2: FAIL. Step 3: Implement.** IP via `WC_Geolocation::get_ip_address()`.
- [ ] **Step 4: Green. Commit** `feat(location): store-level default locality policy`.

## Task 15: Pickup integration — #159 (spec §4.5.4–5)

**Files:**
- Create: `woodev/shipping-method/pickup/class-provider-selection-scope.php` (framework default `Selection_Scope` backed by the location service)
- Modify: `woodev/shipping-method/pickup/class-point-query.php`, `interface-point-source.php` (record/key addressing)
- Modify: `woodev/shipping-method/assets/js/frontend/pickup-mount.js` / `pickup-datasource.js` (locality from the location config block, not a DOM read)
- Test: `tests/unit/Shipping/Pickup/ProviderSelectionScopeTest.php`, extend `PointQueryTest`, `tests/js/pickup-mount.test.js`, `tests/js/pickup-datasource.test.js`

- [ ] **Step 1: Read first (Serena):** `interface-selection-scope.php`, `class-pickup-selection.php`,
  `class-point-query.php`, current `resolveLocality( config )` in `pickup-mount.js`, plus gotchas
  `an-empty-domain-key-is-not-a-key`, `session-key-vs-order-meta-prefix`. The s66/s65 work here is
  fresh and the tests are mutation-hardened — extend, don't rewrite.
- [ ] **Step 2: Write failing tests:**
  - `Provider_Selection_Scope::current_locality()` returns the locality KEY (`dadata:…`) when a
    customer record exists, `''` when none (empty = refusal to answer — existing discipline);
  - `Point_Query` accepts locality addressing by key+record; a query still carrying only a bbox
    keeps working (viewport path untouched);
  - `Point_Source` implementations receive the record AND their plugin's resolved identity
    (via `Location_Service::resolve_for()`) — fixture source asserts it gets both;
  - JS: `resolveLocality` reads the config's `current.key`, updated after a `/select` round-trip
    (listen to the cascade's applied event), NOT `document.getElementById(…_city).value`;
  - session `[location][type]` map now keyed by the KEY: a remembered point under
    `dadata:X` is NOT restored when the current key is `cdek:Y` (provider-switch miss —
    spec D5) — extend the existing persistence tests, session KEY names unchanged.
- [ ] **Step 3: FAIL. Step 4: Implement.** Plugins may still override `Selection_Scope` (their
  session keys are theirs); the framework default becomes provider-backed. Value-shape change
  inside the session map = clean-break internal (established closing #143).
- [ ] **Step 5: FULL jest + unit suites green. Commit** `feat(pickup): points and selection addressed by locality key (#159)`.

## Task 16: Clean-break removals + docs + rig pass

**Files:**
- Delete: `woodev/shipping-method/address/interface-address-normalizer.php`, `class-null-address-normalizer.php`
- Modify: `woodev/shipping-method/class-shipping-plugin.php` (remove `get_address_normalizer()`)
- Modify: `woodev/class-plugin.php` (remove dead asset registrations `jquery-suggestions`, `woodev-dadata-suggestions` — verified dead in s66)
- Delete: `woodev/assets/js/frontend/jquery.suggestions.js`, `woodev-dadata-suggestions.js`
- Modify: docs (`docs-internal/CURRENT-STATE.md`, `SESSION-LOG.md`, gotchas index; public `docs/` NOT touched — operator decision s13)

- [ ] **Step 1:** grep-gate before deleting: `get_address_normalizer|Address_Normalizer|jquery-suggestions|woodev-dadata-suggestions`
  must have zero call sites outside the deleted files (re-verify — do not trust s66's measurement
  across the new code).
- [ ] **Step 2:** delete, regenerate class-map, FULL `composer check` + jest + integration green.
- [ ] **Step 3:** new gotcha files: WC-autocomplete-namespace wrap (Task 12), any traps discovered.
- [ ] **Step 4: Commit** `refactor(location)!: remove superseded address-normalizer seam and dead dadata assets`.
- [ ] **Step 5: Rig verification (operator-facing, PR-D gate):** on `/classic-checkout/` with a
  real DaData token: (1) region-first pipeline: region → scoped city suggestions → scoped address →
  postcode autofilled; (2) address-first: all ancestors backfilled from one selection; (3) city-only
  DOM (no region field): country-wide city search; (4) parent clearing cascades, postcode edit
  inert; (5) reload → record restored (dual store), pickup point survives per `[location][type]`;
  (6) WC autocomplete toggled ON with a fake provider → suppressed for RU, alive for a non-RU
  country; (7) provider switch to the fixture `list` provider → related-list mode appears, caches
  miss cleanly. UI blocks wait for operator confirmation before merge (merge policy).

---

## Self-review (spec coverage)

| Spec item | Task |
|---|---|
| D1 own contract shape | 2, 8, 9 |
| D2 per-country arbitration, static countries | 2, 6, 11, 12 |
| D3 bundled DaData | 7 |
| D4 server-side tokens | 7, 8, 9 (no-leak test) |
| D5 prefixed keys, derive helper, provider-switch miss | 1, 15 |
| D6 neutral widget, no suggestions-js | 10 |
| D7 modes gated by capability | 13 |
| D8 persist-on-select + explicit `update_checkout` | 11 |
| D9 lazy adapter + cache | 5, 6 |
| D10 dual store | 4 |
| D11 default locality | 14 |
| D12 contract-shaped suggest + raw passthrough | 1, 7 |
| D13 postcode derived/write-only | 11 |
| D14 Address_Normalizer + assets removal | 16 |
| §4.3 adapter obligation `_doing_it_wrong` | 5 |
| §4.4 field-presence variants | 9, 11 |
| §4.6 lazy default before cart/checkout | 14 |
| §4.7 degradation (no provider, unsupported country, no WC feature) | 6, 11, 12 |
| §7 testing expectations | распределены по задачам; rig list in 16 |

## Related

- Spec: `docs-internal/specs/2026-08-12-location-provider-design.md`
- Brief: `docs-internal/research/2026-08-11-locality-field-brainstorm-brief.md`
- Prior art in-repo: `docs-internal/archive/plans/2026-07-06-checkout-field-layer-plan.md` (§8)
- Cards: #273, #127, #159
