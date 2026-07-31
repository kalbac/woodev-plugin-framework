# SP-5 — Pickup points + map (§7): design

> Status: **APPROVED** (operator, s44 · 2026-07-30). Supersedes the §7 notes in
> `2026-06-25-shipping-module-decisions.md` where they conflict — see "Decisions that changed".
> Builds directly on §8 (`2026-07-06-checkout-field-layer-design.md`), merged in s44 (PR #132).
> `@since 2.0.2`, VERSION unchanged.

## 1. Problem

§8 shipped the checkout field layer: an external store, a field registry, a classic adapter,
`woodev/v1` field-source REST, and A2 validation gating. It deliberately stopped at an **anchor** —
a hidden `data-woodev-pickup-slot="<field_id>"` div placed next to the pickup field and revealed only
when a pickup-requiring shipping method is chosen. Nothing mounts into that anchor yet, so on the rig
the A2 gate is released by a demo stub button in the test fixture.

Until something real mounts there, delivery checkout is non-functional: the customer cannot pick a
pickup point, and every target carrier (CDEK, Yandex, and the upcoming OZON Logistics) is
pickup-centric.

SP-5 fills that anchor: from the button the customer sees, through the map, to the point being
persisted on the order.

## 2. What already exists (and why most of it goes)

A pickup subsystem was written in June (`8887ce0`, an autodev cycle), ~1500 lines:

| File | Lines | Fate |
|---|---|---|
| `checkout/class-pickup-checkout-handler.php` | 554 | **delete** |
| `ajax/class-shipping-ajax.php` | 276 | **delete** |
| `assets/js/frontend/pickup-map.js` | ~330 | **delete** |
| `assets/js/frontend/map-adapter-leaflet.js` | ~230 | **delete** |
| `assets/js/frontend/checkout.js` | ~250 | **delete** |
| `map/class-leaflet-map-provider.php` | ~130 | **delete** |
| `checkout/views/html-pickup-modal.php`, `html-pickup-balloon.php` | ~58 | **delete** |
| `assets/css/frontend/pickup-map.css` | 130 | **delete** |
| `map/interface-map-provider.php` + `class-map-provider-registry.php` | ~180 | **keep, re-purpose** |

It has **zero consumers, zero tests, and was never validated by a plugin**. It predates §8 and
duplicates what §8 now owns: its own `handle_checkout_process`, its own order-meta save, its own
chosen-method detection, its own nonce/AJAX surface. It is also built on **admin-ajax**, while §8
resolved the "AJAX vs REST" question (left open in the decisions doc for SP-3/SP-5) in favour of
`woodev/v1` REST.

The clean-break policy applies without reservation: these are internal APIs with no installed-site
data contract and no callers. Delete, do not shim.

**Why the two seams survive.** `Map_Provider` + `Map_Provider_Registry` are a sound idea placed on the
wrong axis. They are kept, with their meaning redefined (§4.3).

### Reference implementation

`plugins-reference/woocommerce-yandex-delivery` is the UX target (operator: his most UI/UX-friendly and
most stable map). It offers two modes via a `map_type` setting:

- `native` — Yandex's turnkey widget (`ndd-widget.landpro.site/widget.js`);
- `standard` (default) — **our own map on ymaps 2.1**, `assets/js/frontend/wc-yandex-delivery-widget-map.js`.

Behaviours to reproduce, taken from that file and `templates/html-modal-map.php`:

- clustering (`ymaps.Clusterer`) with a custom balloon layout;
- a **list drawer synchronised with the viewport** — `boundschange` → `ymaps.geoQuery` filters the
  list to the points currently visible; the list is a map control, not a sibling panel;
- a rich balloon: name, postal code, full address, collapsible "Как добраться", payment methods,
  phone, max weight, and a select CTA;
- **payment-aware gating** — when COD is the chosen payment method and the point does not accept it,
  the balloon explains why and the select button is disabled;
- address search bounded to the served area (`geocode(..., {boundedBy, strictBounds})`);
- a point-type filter, shown only when more than one type exists.

## 3. Decisions that changed

Three earlier decisions are overturned here, each for a reason discovered in the code or from the
operator during the s44 brainstorm.

**3.1 Leaflet is dropped; ymaps is the only own-map provider we ship.**
The decisions doc said "our map (Yandex.Maps) = primary" while the skeleton shipped a Leaflet default —
the two were never reconciled. Leaflet loses on two counts: it is only a renderer, so tiles still cost
a key or violate OSM's usage policy at plugin scale; and the five-method `MapAdapter` contract cannot
express the reference UX, which rests on `Clusterer`, `boundschange` + `geoQuery`, `map.controls.add`,
`SearchControl` with bounded geocoding, and `templateLayoutFactory`. A second provider would be a second
full build, not a thin adapter — so "provider-agnostic" was agnostic only while one provider existed.

**3.2 Pochta does not require an iframe.**
The decisions doc recorded "iframe is first-class — Pochta requires it, no per-city points API". The
operator established that Pochta *does* have such a method (non-public, found while reading their own
map's code); the iframe was a workaround, not a constraint. The real distinction is a **loading
strategy**, not a mode.

**3.3 Two loading strategies are first-class, both implemented in SP-5.**
The driver is not Pochta but **OZON Logistics**, the next plugin: its `DeliveryMap` operation takes a
bounding box and `DeliveryPointInfo` fetches a single point's details separately. Designing only for
bulk loading would force every bbox-based carrier into an iframe.

## 4. Architecture

### 4.1 Responsibility split

The whole design rests on one rule: **nobody but the plugin ever sees a carrier's raw response.**

| Layer | Owns |
|---|---|
| **Plugin (domain)** | The carrier API: how to fetch, how to authenticate, how to match cities/codes, how to cache, how to translate the carrier's payload into a normalized point. Declares its loading strategy. |
| **Framework (mechanism)** | The normalized point shape, REST endpoints, the modal shell, mounting into the §8 anchor, session + order-meta persistence, constraint checking, address replacement, degradation. |
| **Provider (presentation)** | Everything visible inside the map container: clustering, drawer, balloon, search, type filter, mobile behaviour. |

### 4.2 Component map

```
PLUGIN
  Point_Source (interface)
      fetch_points( Point_Query ): Pickup_Point[]
      fetch_details( string $id ): ?Pickup_Point
      get_strategy(): 'bulk' | 'viewport'

FRAMEWORK  (Woodev\Framework\Shipping\Pickup\*)
  Pickup_Point            value object + normalization + validation
  Point_Query             value object: locality | bounds | query | limit
  Pickup_Handler          orchestration: anchor mount, session, order meta,
                          address replacement, A2 integration
  Constraint_Checker      COD + weight rules, client-mirrored and server-authoritative
  Pickup_Rest_Controller  woodev/v1 routes
  Modal_Shell (JS)        vanilla dialog: focus trap, Esc, aria, mobile full-screen
  Map_Provider_Registry   resolves the active provider

PROVIDERS  (Woodev\Framework\Shipping\Map\*)
  Yandex_Map_Provider     our ymaps map, reference UX
  Embedded_Provider       carrier widget / iframe in the same shell
```

### 4.3 The provider seam, re-pointed

The old seam asked "which library draws the map" and let a provider-agnostic core own the UX. The new
seam asks **"where does the map come from"**, and the provider owns everything inside its container:

```js
init( container, config, dataSource ) -> Promise<void>
on( 'select', cb )   // cb( normalizedPoint )
on( 'error', cb )    // cb( { code, message } )
destroy()
```

There is no `setPoints`. The framework hands the provider a **dataSource** instead:

```js
dataSource.fetchPoints({ locality?, bounds?, query? }) -> Promise<Point[]>
dataSource.fetchDetails( pointId )                     -> Promise<Point>
```

Inverting control this way is what makes both strategies fit one contract: only the provider knows
that the viewport moved or that a balloon opened, so only the provider can decide when to ask.

- `strategy: 'bulk'` → the provider calls `fetchPoints({locality})` once and filters locally. This is
  exactly the reference behaviour, unchanged.
- `strategy: 'viewport'` → the provider calls `fetchPoints({bounds})` on `boundschange`, debounced
  (300 ms) and de-duplicated by point id, and calls `fetchDetails(id)` lazily when a balloon opens.

A future Google Maps or Leaflet provider reimplements the drawer and balloon for itself. That is
honest: it would have to anyway.

### 4.4 Normalized point

Grounded on two real carriers — CDEK (`$chosen_delivery_point`: `location.{address,city,postal_code}`,
`allowed_cod`, `weight_max`, `type: PVZ|POSTAMAT`) and Yandex (balloon template: `name`,
`address.{full_address,postal_code,locality,comment}`, `short_address`, `instruction`,
`payment_methods[]`, `contact.phone`, `maxWeightGross`, `type`).

```
REQUIRED
  id            string, unique within the carrier
  name          string
  lat, lng      float
  address       string, full address on one line
  type          { code, label } — the carrier's own vocabulary; the framework
                never interprets the code, only passes it through

OPTIONAL (rendered when present)
  short_address · locality · postal_code · phone
  instruction   how to get there
  work_time     opening hours
  payment_methods[]
  photos[]

CONSTRAINTS (affect selectability)
  accepts_cod   bool|null   — null = unknown, treated as "accepts"
  max_weight    int|null    — GRAMS; the framework normalizes, carriers differ
```

`Pickup_Point` rejects a payload missing a required field, and `esc_html`s every string server-side
before it reaches the browser — the same rule §8 applies to field-source labels.

### 4.5 Constraints

COD and weight are present in both existing plugins and in OZON, so they are mechanism, not domain.

**The verdict is always computed server-side** and travels with the point payload as
`selectable: { allowed: bool, reason: string|null }`. The client never re-implements the rules — it only
renders the verdict. This avoids the mirrored-evaluator maintenance that `show_if` needed in s40, and it
keeps one source of truth.

The framework checks in both places:

- **Client** — the provider reads `selectable` off the point and disables the select button, showing
  `reason` in the balloon (reference behaviour).
- **Server** — `Constraint_Checker` re-checks on `woocommerce_checkout_process` against the point the
  order actually carries. The client check is UX; the server is the authority. This mirrors the A2
  lesson from s44: a client-side gate must never be the only gate.

**Timing under the viewport strategy.** `accepts_cod` and `max_weight` are frequently absent from a
carrier's *list* response and only arrive with the *details* call (OZON's `DeliveryPointInfo` is exactly
this shape). So:

- a point whose constraint inputs are unknown is emitted with `selectable.allowed = true` — unknown is
  not a prohibition, and greying out points the customer could legitimately use is worse than a late
  rejection;
- the verdict is **recomputed on `fetchDetails`**, so opening the balloon — which is the moment before
  any selection — always shows the authoritative answer;
- the server re-check at `woocommerce_checkout_process` remains the backstop if a point was somehow
  selected without its details being fetched.

Under the bulk strategy the inputs are present from the start and the verdict never changes.

Extension point: `woodev_shipping_pickup_point_selectable` — filters the verdict, receiving the point,
the cart, and the chosen payment method.

CDEK's existing behaviour (block checkout when cart weight exceeds `weight_max`, with the limit named
in the message) is the model for the wording.

### 4.6 Address replacement

Both reference plugins write the selected point's address into the checkout address fields. CDEK writes
`city`/`address_1`/`postcode` into **both** billing and shipping; Yandex has a `replace_address_fields`
toggle, default on.

The framework writes into **whichever fieldset WooCommerce currently treats as the delivery address**,
using WooCommerce's own predicate rather than a preference of ours. From `class-wc-checkout.php`:

- `get_posted_address_data($key, 'shipping')` returns the **billing** value whenever
  `ship_to_different_address` is false (line 1391);
- `ship_to_different_address` is forced false in `billing_only` mode via
  `wc_ship_to_billing_address_only()` (line 767);
- `maybe_skip_fieldset('shipping')` drops the shipping fieldset entirely when the flag is false (line 742).

So:

| Store configuration | Target |
|---|---|
| Force shipping to billing (`billing_only`) — the operator's recommendation | `billing_*` (no shipping fields exist) |
| Default, "ship to a different address" unchecked | `billing_*` (WooCommerce copies to shipping itself) |
| "Ship to a different address" checked | `shipping_*` (billing stays the customer's) |

This needs no setting of ours and, usefully, never overwrites a genuinely separate billing address: the
only configuration where billing and shipping differ is the third, and there we do not touch billing.

Context that drove this: in RU/CIS there is no practical "billing address" concept — it equals the
delivery address — and the operator recommends merchants enable force-shipping-to-billing, which removes
the shipping fields altogether. A rule that wrote only to `shipping_*` would write nowhere visible.

**A toggle disables replacement entirely** (operator's call — Yandex has one today, so someone needed it).

**Writes go through the §8 store, never straight to the DOM.** `billing_city` is a §8 takeover select
with a bounded option set and `billing_state` is owned by WooCommerce via `woocommerce_states`. A raw
DOM write reproduces exactly the bug class fixed in s42/s44: the value is wiped on `update_checkout`, or
never takes because no matching `<option>` exists. When the point's city is absent from the select, the
framework adds it as an option first — the same fix applied to suggest-takeover in s44.

### 4.7 Map provider configuration and the API key

Yandex Maps requires an API key. The reference hardcodes one (`8bc059fe-…`) behind a
`woodev_yandex_map_api_key` filter. The operator's reason is sound and preserved: obtaining a key from
Yandex is awkward enough that many merchants simply cannot, and a plugin that does not work until the
user solves someone else's console is a bad plugin.

- When a plugin uses the map with the Yandex provider, the framework **auto-registers an optional
  "API-ключ Яндекс.Карт" settings field**.
- Empty → the framework's fallback key, supplied **through a filter, not a constant**, so rotating it
  never requires a framework release.
- The field is **not** marked `sensitive`. SP-2 gave us masking and the reflex "key ⇒ secret" is wrong
  here: a JS map key ships to the browser inside the script URL and cannot be hidden. Masking would be
  security theatre and would stop the merchant seeing what they pasted.

Known risk, accepted and recorded: one shared key is a single quota failure point across all installs.
It has worked for years, so this is a watch item, not a blocker — but §4.9 requires the map to degrade
visibly rather than render an empty rectangle when it fails.

### 4.8 Modal shell

Own vanilla shell, not `wc-backbone-modal`:

- SP-11 needs the same picker inside the React blocks checkout, where `wc-backbone-modal` is not
  available; one shell serves both;
- no Backbone/underscore dependency on the storefront;
- it survives stores where `wc-backbone-modal` or a dependency is not loaded — a case the operator has
  actually hit;
- the provider owns the inside regardless, so the shell stays small.

Responsibilities: open/close, backdrop, focus trap, Esc, `role="dialog"` + `aria-modal`, restoring focus
to the trigger on close, and a full-screen layout below a mobile breakpoint.

**Mobile.** Below 782px (the WordPress breakpoint) the modal goes full-screen, the drawer becomes a
bottom sheet with a drag handle, and the balloon opens as a bottom sheet rather than an overlay.

### 4.9 Failure and degradation

Never show an empty rectangle. Every failure has a visible state and, where sensible, a retry:

| Failure | Behaviour |
|---|---|
| Map script fails to load / key rejected | Error state inside the shell with the reason and a retry button |
| `fetchPoints` fails | Error state with retry; an already-drawn point set is kept |
| `fetchDetails` fails | Balloon shows what the list already knows + a retry; selection stays possible |
| Zero points for the locality/bbox | Explicit "no points here" state, not a blank map |
| No JavaScript | No map. The pickup field stays empty and the §8 A2 gate blocks the order server-side with a clear message — the same progressive-enhancement stance §8 took |

The `<select>` fallback mode from the decisions doc ("implement only if trivial, else drop") is
**dropped**: a flat select cannot express a bbox-driven carrier, so it would serve only the bulk half
and mislead about coverage.

### 4.10 Caching

Caching belongs to the **plugin**: only it knows the carrier's TTLs, quotas and invalidation rules. The
framework adds no cache of its own; it points implementers at the existing
`Woodev_Abstract_Cacheable_API_Base` / `Cacheable_Request_Trait`, which already solve this.

## 5. REST surface

`woodev/v1`, mirroring the §8 field-source controller's conventions (per-plugin route id, guest
accessible, `esc_html`ed output, no carrier credential ever reaching the client).

```
GET  /woodev/v1/shipping/pickup/{plugin_id}/points
       ?locality=…            (bulk strategy)
       ?bbox=lat1,lng1,lat2,lng2   (viewport strategy)
       &q=…                   (optional search term)
GET  /woodev/v1/shipping/pickup/{plugin_id}/points/{point_id}
```

- Read endpoints are public because checkout guests need them; they expose only normalized points.
- `bbox` is validated and area-capped to stop a client requesting the whole planet.
- The selection **write** goes through the §8 field value (`carrier_pickup_point`) and WooCommerce's own
  checkout nonce — SP-5 adds no second write endpoint and no second nonce.

## 6. Persistence

- During checkout the selected point lives in the §8 store and in the WC session.
- On order creation the point id goes to the §8 pickup field's order meta (the field id **is** the meta
  key — §8's contract), and the full normalized point is stored alongside it for the admin/export
  stages (SP-7, SP-10).
- All order meta access goes through `Woodev_Order_Compatibility` (HPOS-safe; gotcha
  `hpos-order-meta-safety`).
- Meta keys are framework-neutral: the plugin supplies the field id, so no installed-site contract
  string is hardcoded here. Per-plugin key preservation is settled in each plugin's migration checklist.

## 7. Testing

- **Unit** — `Pickup_Point` normalization and rejection; `Point_Query` validation incl. bbox capping;
  `Constraint_Checker` (COD, weight, unknown values, the filter); the address-target resolver against
  all three WooCommerce configurations.
- **Integration** — both REST routes, guest access, bbox validation, per-plugin route registration.
- **Jest** — modal shell (focus trap, Esc, restore focus), dataSource debounce/de-dup.
- **Rig e2e, mandatory before merge** — the fixture gains a second point source so **both strategies are
  exercised live**: bulk (all points at once) and viewport (bbox + lazy details). Verify: button → modal →
  map → select → A2 gate releases → address fields updated through the store → values survive
  `update_checkout` → order placed with the point in meta → server rejects a constraint-violating point
  when the client gate is bypassed.

Drive the browser with **chrome-devtools MCP, not Playwright MCP** (gotcha
`playwright-mcp-does-not-fire-wc-checkout-ajax`).

## 8. Cross-cutting constraints

- PSR-4 `Woodev\Framework\Shipping\*`, `Snake_Case` classes, short arrays, type declarations,
  docblocks with `@since 2.0.2`.
- Regenerate `woodev/class-map.php` after adding classes (`bin/generate-class-map.php`).
- No `_n()` — Russian is the source language (gotcha `russian-source-i18n-plural-n`).
- User-facing strings in Russian; docs, code and commits in English. Note issue #133: the strings left in
  the deleted `class-pickup-checkout-handler.php` disappear with it, which closes part of that card.
- No Composer autoload at runtime.

## 9. Out of scope

Rate calculation from the selected point (SP-6), the point shown in order admin (SP-10), the blocks
adapter (SP-11). The shell and the provider seam are built so SP-11 reuses them rather than
reimplementing.

## 10. Decisions changed during implementation (s45, 2026-07-31)

Seven decisions in this document were amended while building T1–T12. Each is recorded here rather
than edited into the text above, so the reasoning survives.

**10.1 `to_array()` is canonical; escaping moved to `to_browser_array()`.** §4.4 said `Pickup_Point`
"`esc_html`s every string server-side before it reaches the browser". Implemented inside `to_array()`,
that corrupted **data at rest**: the same method feeds order meta (§6) and the WC session, so an
address like `ООО "Ромашка"` would persist as `ООО &quot;Ромашка&quot;` and be sent to the carrier
verbatim on export at SP-7. The guarantee is preserved by escaping at the boundary — which is what
§8 already does, in its *controller*, not in its `Field` value object.

**10.2 The bbox cap is per-side, not per-area.** §5 said "area-capped". A 100 sq-deg area cap accepts
`0.27° × 360°` — a strip around the entire circumference of the planet, the exact abuse the cap
exists to prevent. Replaced with a 10° cap on each side, which is strictly stronger and makes the
"roughly a 10°×10° window" description literally true.

**10.3 Weight is checked before COD.** The spec did not specify an order; it fell out of the sample
implementation. Weight is **unfixable** at the picker — nothing the customer does at checkout clears
it except removing items — while COD is fixable by switching gateway. Showing the fixable reason
first sends the customer to change payment method and walk into a second wall.

**10.4 `replaceAddress` carries `billingOnly`, not a resolved `target`.** §4.6's rule is right, but
resolving it **server-side at render** goes stale: `ship_to_different_address` is a live checkbox.
A stale `billing` target would write the pickup address over the customer's real billing address
while leaving the actual delivery fieldset untouched — defeating §4.6's own guarantee. The stable
half (`billingOnly`, a store setting) ships; the browser re-applies the rule at write time.

**10.5 The full point's order-meta key comes from the plugin.** §6 says the framework hardcodes no
contract string. A framework-coined `{field_id}_full` suffix violated that and bypassed
`Shipping_Order_Handler::store_pickup_point()`, which exists for exactly this job and had zero
callers. `Pickup_Handler` now delegates to it and **skips** full-point persistence when the plugin
supplies no mapping — not storing the extra copy is better than the framework inventing a key.

**10.6 The Yandex Maps fallback key is the plugin's obligation, not the framework's** (operator
decision). §4.7 implied the framework supplies a fallback behind a filter. It does not: the key is a
**required constructor argument** on `Yandex_Map_Provider`, exposed as `get_fallback_map_key()` and
wrapped at the call site as `apply_filters( 'woodev_shipping_map_fallback_api_key', $this->get_fallback_map_key() )`
so a site can still override. Two reasons: a framework-level key pools the quota across *all*
carriers so one rate-limit kills every map at once, and the framework is vendored **into** each
plugin, so rotating a framework constant would need every plugin re-released anyway — the stated
benefit of a filter over a constant is not achieved. Consequence: the framework registers no provider
by default, because it cannot construct one. See ADR-009 and its addendum.

**10.7 The §8 store gained an instance registry.** Not anticipated at all. The classic adapter kept
its store instances in a local IIFE array, so the mount could not reach the instance the gate reads,
and a second instance would diverge silently. `getStoreForField()` resolves by field ownership.
See gotcha `js-store-instance-registry-cross-module`.

Also amended in §4.9: the "already-drawn point set is kept" rule needs a **non-destructive** state.
`modal.showError()`/`showEmpty()` replace the container the provider drew into, so the shell gained
`showNotice( message, onRetry )` — a banner beside the map — used once a point set exists. And a
retry constructs a **fresh provider** rather than re-`init()`ing a live one, which the contract
(`init`/`on`/`destroy`) never defined.

## Related

- [[2026-07-06-checkout-field-layer-design]] — §8, the layer this mounts into
- [[2026-06-25-shipping-module-decisions]] — the shipping programme; §7 partially superseded here
- `docs-internal/gotchas/checkout-field-takeover-woocommerce-states.md`
- `docs-internal/gotchas/playwright-mcp-does-not-fire-wc-checkout-ajax.md`
