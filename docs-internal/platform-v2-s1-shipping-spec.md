# Platform v2 — S1 Shipping Universal Module — Architecture Spec

> Status: **APPROVED DIRECTION** (operator blessed the four §6 decisions 2026-06-04).
> Author: Planner session (Opus 4.8). Branch: `autodev/loop-bootstrap`.
> This is a **spec + decomposition**, not code. The autodev loop executes the
> companion task queue in `.autodev/queue/pending/`.
> Provenance: grounded in `platform-v2-direction-audit-2026-06-03.md` §7, `PLANS.md`
> §3.2, the existing `woodev/shipping-method/` skeleton, the mature `woodev/payment-gateway/`
> module (target shape), and the read-only `woocommerce-yandex-delivery` reference
> (PVZ-map + data contracts). No detail is from memory.

---

## 0. Operator-blessed direction decisions (the locks)

| # | Decision | Chosen | Consequence |
|---|----------|--------|-------------|
| **a** | PVZ-map provider abstraction shape | **A1 — two orthogonal axes** | PHP owns the pickup-point model, point *sourcing*, filtering, selection-state persistence, the checkout-modal *shell*, and the AJAX surface. A **JS map adapter** (one per provider; Leaflet default ships in framework, Yandex adapter ships in the yandex plugin) owns rendering + balloon. A thin PHP `Map_Provider` descriptor only registers assets/API-key field. Sourcing ≠ rendering — they never merge. |
| **b** | Pickup-point model + warehouse store schema | **B1 — interface over per-plugin storage** | Framework defines `Pickup_Point`/`Warehouse` value objects + a `Warehouse_Store` interface + a default table-backed store. **Each plugin keeps its own storage**; yandex preserves `{$wpdb->prefix}wc_yandex_delivery_warehouses` byte-for-byte and implements the interface over it. **No canonical shared table, no data migration.** `Pickup_Point` = fixed core schema + `raw`/`meta` escape hatch for carrier-specific fields. |
| **c** | Yandex data-contract list completeness | **Complete; operator supplies the 3 unverifiable items** | Static survey extracted all contract strings. Three items a read-only static survey cannot confirm — EDD download ID, exact warehouse table name, and the `wc_yandex_update_order` cron payload shape — are flagged **PENDING OPERATOR** in the checklist and will be filled from a live install. They are release-blocking but do not block the framework-build queue. |
| **d** | S1 scope cut | **D1 — all five areas, trimmed to yandex+edostavka need** | PVZ-map phased first. Generic inbound-webhook base ships as *scaffolding* but its validation is deferred (yandex is outbound-only — no webhook to test against; edostavka validates it later). **Deferred out of S1:** generic label/act printing abstraction, a third map provider (Google/2GIS/…), React admin UI (S5), box-packer integration (S2 — known seam, not built here). |

---

## 1. Where this sits in the existing skeleton

`woodev/shipping-method/` (PSR-4 `Woodev\Framework\Shipping\`) is **~20% built — the rate/method
spine is the part that exists; everything customer-facing is empty.** The spec **extends**
this skeleton; it does not replace it.

**Already substantial (do NOT reinvent — extend in place):**

| File | State | Role |
|------|-------|------|
| `class-shipping-plugin.php` | ~95% | Plugin entry; method registry, WC hooks, admin notices. `extends Woodev\Framework\Woocommerce_Plugin`. Abstract: `get_shipping_method_classes()`, `get_api()`, `get_integration_option()`. |
| `class-shipping-method.php` | ~85% | Abstract `WC_Shipping_Method`. Constants `TYPE_COURIER/PICKUP/POSTAL`, `FEATURE_*`. Final `calculate_shipping()` → abstract `calculate_rate()`. Has `get_order_meta_prefix()`, `get_method_id()`, type predicates. |
| `class-shipping-method-{courier,pickup,postal}.php` | type markers | Each pins `get_delivery_type()`. Postal adds `require_postal_code`. **Pickup is an empty marker — PVZ wiring goes here.** |
| `class-shipping-rate.php` | ~90% | Immutable rate DTO → WC `add_rate()` format. |
| `class-shipping-helper.php` | ~80% | Unit conversion, package extraction, cost utilities. No PVZ code. |
| `settings/class-shipping-integration.php` | ~75% | `WC_Integration` base; env (prod/test), debug. Abstract `get_method_form_fields()`, `init_plugin()`. No DaData. |
| `api/interface-shipping-api.php` | ~85% | **Already declares** `calculate_rates()`, `get_pickup_points()`, `create_order()`, `get_order()`, `cancel_order()`, `get_tracking()`. This is the carrier seam — the *point-source* axis of decision (a) already exists here. |
| `exceptions/class-shipping-exception.php` | stub | extends `Woodev_Plugin_Exception`. |

**Empty (the ≈80% gap this spec fills):** `checkout/`, `assets/`, most of `admin/`, `api/` impls.
**No PVZ / map / warehouse / checkout-modal code exists anywhere in the skeleton.**

---

## 2. Architectural reference — mirror payment-gateway maturity

`woodev/payment-gateway/` is the target shape (`PLANS.md` §3.2: "архитектурный референс").
Its layering, mapped onto shipping:

| payment-gateway layer | shipping equivalent (this spec) |
|---|---|
| `class-payment-gateway-plugin.php` (orchestrator) | `class-shipping-plugin.php` (exists) |
| `class-payment-gateway.php` + Direct/Hosted variants | `class-shipping-method.php` + Courier/Pickup/Postal (exists) |
| `handlers/` (payment/hosted/capture) | `order/` (order/tracking/shipment/webhook handlers) |
| `payment-tokens/` (token model + handler) | `pickup/` (point model + source + selection) |
| `admin/` (order/user/token admin + setup wizard + `views/`) | `admin/` (order column/metabox + warehouse admin + status view) |
| `rest-api/` (controller extending `Woodev_REST_API`) | `rest-api/` (warehouses + pickup-points controllers) |
| `api/` (interface + typed responses) | `api/` (interface exists; add abstract HTTP base + typed responses) |
| `exceptions/`, `assets/` | `exceptions/` (exists), `assets/` (new) |

New layers shipping needs that payment-gateway lacks (PVZ is shipping-specific):
`pickup/`, `map/`, `address/`, `checkout/`, `ajax/`.

---

## 3. Namespace & platform-neutral rules (carried from S0)

1. **All new code is PSR-4 `Woodev\Framework\Shipping\*`.** No legacy `Woodev_*` for new classes.
   (payment-gateway is legacy-named; shipping is already namespaced — keep it that way.)
2. **No installed-site contract string is hardcoded in the framework.** Concrete method
   IDs, option keys, meta keys, table names, cron hooks, REST namespaces belong to plugins.
   The framework provides *mechanisms* (e.g. `get_order_meta_prefix()`, `Warehouse_Store`
   interface), the plugin supplies the *strings*. This is what keeps S1 from minting new
   release-blocking contracts.
3. **WC seams stay out of platform-neutral bases.** The shipping module is intentionally
   WC-coupled (it `extends WC_Shipping_Method` / `WC_Integration`) — that is fine, shipping
   is a WC concept. But value objects (`Pickup_Point`, `Warehouse`, `Shipping_Rate`) and the
   `Map_Provider` / `Warehouse_Store` / `Address_Normalizer` interfaces must be **pure PHP**,
   no WC calls — so they are unit-testable with Brain Monkey and reusable off-WC later.
4. **Type declarations + docblocks (`@since`, `@param`, `@return`) on every public/protected
   method.** Pure methods `static`. Hook callbacks named `handle_{hook}` + `@internal`.

---

## 4. The five abstractions (scope from audit §7)

### 4.1 PVZ / pickup-point map abstraction — Phase 1 (FIRST, highest value+risk)

The decision-(a) two-axis design. Seven concerns, each a discrete abstraction:

#### (i) Pickup-point data model — `pickup/class-pickup-point.php`
Pure immutable value object. **Fixed core schema** (from the yandex point survey) + escape hatch:

```
Pickup_Point {
  code: string            // carrier point id (yandex: $detail->id)
  type: string            // 'pickup_point' | 'terminal' | 'postamat' | 'office' (carrier maps into these)
  name: string
  address_full: string
  address: array          // {street, house, building, housing, apartment, postal_code, city}
  lat: float, lng: float
  work_hours: array
  payment_methods: array  // ['cash_on_receipt','card_on_receipt',...] — drives COD filtering
  max_weight: ?int (g)    // size/weight limits for filtering
  max_dimensions: ?array
  phone: ?string
  raw: array              // carrier-specific blob — the escape hatch (decision b)
}
```
Public surface: typed getters, `from_array(array): self`, `to_array(): array`, `jsonSerialize()`.
**Extension point:** carriers map their API payload into a `Pickup_Point` via a factory in
their `Pickup_Point_Source`; non-core fields ride in `raw`.

#### (ii) Map provider — `map/interface-map-provider.php` + registry + Leaflet default
PHP descriptor only (rendering is JS). The **JS contract** is the real interface boundary.

```
interface Map_Provider {
  get_id(): string                       // 'leaflet' | 'yandex'
  enqueue_assets(): void                 // its map JS + the matching JS adapter
  get_settings_fields(): array           // e.g. API-key field (yandex needs one; leaflet doesn't)
  get_js_adapter_handle(): string        // the registered script handle of its JS adapter
  get_localized_config(): array          // passed to JS (api key, locale, default center)
}
```
- `map/class-map-provider-registry.php` — `register(Map_Provider)`, `get(id)`, `get_default()`.
- `map/class-leaflet-map-provider.php` — ships in framework; the no-API-key default.
- **Yandex provider is NOT in the framework** — it ships in the yandex plugin and registers
  itself. The framework only guarantees the seam + the Leaflet fallback.

**JS adapter contract** (documented in `assets/js/frontend/pickup-map.js`, the provider-agnostic core):
```
MapAdapter {
  init(containerEl, config) -> Promise
  setPoints(Pickup_Point[]) -> void
  on('select', (pointCode) => …)        // user picked a point
  filter(predicateFn) -> void
  destroy() -> void
}
```
`pickup-map.js` orchestrates: fetch points (AJAX) → hand to adapter → relay `select` → persist.
`assets/js/frontend/map-adapter-leaflet.js` implements `MapAdapter` over Leaflet.

#### (iii) Warehouse data store — `pickup/interface-warehouse-store.php` + abstract default + `class-warehouse.php`
Decision (b): interface + default, **no shared canonical table**.

```
Warehouse { id, name, address, lat, lng, contact_{name,phone,email}, work_hours, raw }
interface Warehouse_Store {
  get(id): ?Warehouse
  all(): Warehouse[]
  save(Warehouse): int
  delete(id): bool
}
abstract Abstract_Warehouse_Store implements Warehouse_Store  // table-backed default a plugin MAY use
```
Yandex implements `Warehouse_Store` over its **existing** `wc_yandex_delivery_warehouses`
table (preserve byte-for-byte). A new plugin can extend `Abstract_Warehouse_Store` and get a
table for free. The framework never forces a schema on an existing plugin.

#### (iv) Point sourcing & filtering — `pickup/interface-pickup-point-source.php` + `pickup/class-pickup-point-filter.php`
Sourcing is the **second axis** of decision (a). The existing `Shipping_API::get_pickup_points()`
is the carrier-API source; `Pickup_Point_Source` is the normalizing seam above it:

```
interface Pickup_Point_Source {
  search(array $params): Pickup_Point[]   // params: city, postal_code, lat/lng, limit
}
```
`Pickup_Point_Filter` (pure): filter a `Pickup_Point[]` by `type`, `payment_method`
(COD support), and `max_weight`/`max_dimensions` — exactly the three filters yandex applies.

#### (v) Selection-state persistence — `pickup/class-pickup-selection.php`
Persists the chosen point in the **WC session** during checkout only, keyed by the
plugin-supplied **session key** — the framework hardcodes no contract string (yandex's
installed session key is `chosen_yandex_pickup_point` / `chosen_yandex_pickup_point_test`,
passed in by the plugin). Surface: `set(point)`, `get(): ?Pickup_Point`, `clear()`.
HPOS-irrelevant (session, not order meta).

**Session-only by design (corrected 2026-06-06, autodev critic-s1-p1-pickup-selection).**
The chosen point's *order-meta* persistence is owned by the Phase-3 order handler
(§4.3, `class-shipping-order-handler.php`), which writes/reads under the plugin's **order-meta
prefix** (yandex: `_yandex_delivery_*`, decomposed into `_destination_station_id`/`_address`/…).
The session key (`chosen_yandex_pickup_point`) and the order-meta prefix (`_yandex_delivery_`)
are two DISTINCT installed-site contracts that do not share a namespace, so a single composed
key cannot preserve both. Do **not** add `persist_to_order`/`restore_from_order` here — that
conflation was the original spec bug.

#### (vi) Checkout modal + balloon — `checkout/views/html-pickup-modal.php` + `html-pickup-balloon.php`
The PHP ships the **modal shell** markup + a balloon template; the JS adapter fills them.
Mirrors yandex's `html-modal-map.php`. Wired by the checkout handler (4.2), not these views.

#### (vii) Address normalization — `address/interface-address-normalizer.php` + null default
DaData is pluggable, not baked in:
```
interface Address_Normalizer { suggest(string $query): array; normalize(string $address): array }
class Null_Address_Normalizer implements Address_Normalizer  // no-op default
```
A DaData normalizer ships in the plugin that has a DaData token (yandex), not the framework.

### 4.2 Checkout-field orchestration — Phase 2
- `checkout/class-checkout-fields.php` — declares custom checkout fields (pure definition).
- `checkout/class-checkout-handler.php` — injects fields, handles posted data, runs validation,
  HPOS-safe save. The orchestration backbone.
- `checkout/class-pickup-checkout-handler.php` — specialization wiring the PVZ modal trigger,
  the hidden selected-point fields, and `Pickup_Selection` into the checkout flow.
  Loads `assets/js/frontend/checkout.js`.

### 4.3 Order / tracking / webhook base classes — Phase 3
- `order/class-shipping-order-handler.php` — HPOS-safe order-meta read/write.
  **No hardcoded meta-key suffixes (corrected 2026-06-06, autodev critic-s1-p3-order-handler).**
  The framework hardcodes NEITHER the prefix NOR the suffixes — the plugin supplies the **full
  meta-key map** it must preserve, because every carrier's keys differ and are installed-site
  contracts: edostavka stores `cdek_order_id` / `tracking_code` / `status` under `_wc_edostavka_`;
  yandex stores a *decomposed* set (`_yandex_delivery_destination_station_id` / `_…_address` / …),
  and its chosen-point session key is the separate `chosen_yandex_pickup_point`. A neutral
  composed key like `_yandex_delivery_chosen_point` matches NONE of these and would orphan live
  order data. So the handler takes an explicit map of logical-field → real-meta-key from the
  plugin (e.g. `['carrier_order_id' => 'cdek_order_id', …]`) and reads/writes only those; it
  invents no key of its own.
- `order/abstract-tracking-handler.php` — tracking-status model + display hooks (uses
  `Shipping_API::get_tracking()`).
- `order/abstract-shipment-handler.php` — create/cancel/export shipment to carrier (uses
  `Shipping_API::create_order()/cancel_order()`); retry via `Woodev_Background_Job_Handler`.
- `order/abstract-webhook-handler.php` — **scaffolding only** (decision d): generic inbound
  webhook receiver + signature-verification seam. **No yandex validation** (yandex is
  outbound-only); validated later by edostavka's rewrite.

### 4.4 Admin-UI scaffolding — Phase 4 (mirror payment-gateway admin suite, trimmed)
- `admin/class-shipping-admin.php` — admin bootstrap.
- `admin/class-shipping-admin-order.php` + `admin/views/html-admin-order-metabox.php` —
  order-list column + order metabox (export/track/cancel buttons).
- `admin/class-warehouse-admin.php` + `assets/js/admin/warehouse-admin.js` — warehouse CRUD UI
  over `Warehouse_Store`.
- `admin/views/html-admin-shipping-method-status.php` — **complete the existing stub**
  (it currently says "developer did not complete this block").
- `rest-api/class-shipping-rest-api.php` — REST bootstrap (`extends Woodev_REST_API`).
- `rest-api/abstract-warehouses-controller.php` — warehouses CRUD controller base (mirrors
  yandex `Warehouses_Rest_Api`; namespace supplied by the plugin, e.g. `wc-yandex-delivery`).
- `rest-api/abstract-pickup-points-controller.php` — pickup-search controller base.

### 4.5 Rate / method bases — Phase 5 (extend skeleton, don't replace)
- `api/class-abstract-shipping-api.php` — abstract base implementing `Shipping_API` over
  `Woodev_API_Base` HTTP plumbing (typed request/response wiring), so carriers implement
  thin subclasses.
- Light enhancement to `class-shipping-method.php` only if Phase 1–4 reveal a missing hook
  (e.g. a rate-cache filter). Kept to a single-file, minimal edit.

---

## 5. Module layout after S1 (target tree)

```
woodev/shipping-method/
  class-shipping-plugin.php              [extend: register new subsystems]
  class-shipping-method.php              [exists; minimal enhance only]
  class-shipping-method-courier.php      [exists]
  class-shipping-method-pickup.php       [extend: wire PVZ selection]
  class-shipping-method-postal.php       [exists]
  class-shipping-rate.php                [exists]
  class-shipping-helper.php              [exists]
  api/
    interface-shipping-api.php           [exists]
    class-abstract-shipping-api.php      [NEW P5]
  pickup/                                [NEW P1]
    class-pickup-point.php
    class-warehouse.php
    interface-pickup-point-source.php
    class-pickup-point-filter.php
    interface-warehouse-store.php
    class-abstract-warehouse-store.php
    class-pickup-selection.php
  map/                                   [NEW P1]
    interface-map-provider.php
    class-map-provider-registry.php
    class-leaflet-map-provider.php
  address/                               [NEW P1]
    interface-address-normalizer.php
    class-null-address-normalizer.php
  ajax/                                  [NEW P1]
    class-shipping-ajax.php
  checkout/                              [NEW P2]
    class-checkout-fields.php
    class-checkout-handler.php
    class-pickup-checkout-handler.php
    views/html-pickup-modal.php
    views/html-pickup-balloon.php
  order/                                 [NEW P3]
    class-shipping-order-handler.php
    abstract-tracking-handler.php
    abstract-shipment-handler.php
    abstract-webhook-handler.php
  admin/                                 [NEW P4 + complete existing view]
    class-shipping-admin.php
    class-shipping-admin-order.php
    class-warehouse-admin.php
    views/html-admin-shipping-method-status.php   [complete existing stub]
    views/html-admin-order-metabox.php
  rest-api/                              [NEW P4]
    class-shipping-rest-api.php
    abstract-warehouses-controller.php
    abstract-pickup-points-controller.php
  exceptions/class-shipping-exception.php [exists]
  assets/                                [NEW P1/P2/P4]
    js/frontend/pickup-map.js
    js/frontend/map-adapter-leaflet.js
    js/frontend/checkout.js
    js/admin/warehouse-admin.js
    css/frontend/pickup-map.css
```

---

## 6. Phasing & dependency order

```
P1 PVZ-map (FIRST) ─┬─ pickup model/store/source/selection
                    ├─ map provider iface + Leaflet + JS core/adapter + css
                    ├─ address normalizer iface + null
                    ├─ ajax base
                    └─ wire pickup method
P2 Checkout         ── fields → handler → pickup-checkout (needs P1 selection/ajax/js-core)
P3 Order/track/hook ── order-handler → tracking / shipment / webhook(scaffold)
P4 Admin + REST     ── status-view, admin bootstrap/order/warehouse, rest bootstrap/controllers
P5 Rate/API bases   ── abstract-shipping-api, minimal method enhance
P6 Wiring + gate    ── plugin-wiring (integration) → yandex guards → yandex fixture validation gate
```
Phases overlap in the loop where `file_set`s are disjoint; `depends_on` enforces real ordering.

---

## 7. The yandex-fixture validation gate (mirrors the edostavka pilot)

S0's gate was `tests/unit/EdostavkaPilotFixtureTest.php` over a fixture that asserted two
edostavka contract strings load through the new path. S1's analog:

- **Fixture:** `tests/_fixtures/woodev-yandex-pilot-plugin/` — a yandex-shaped plugin that
  extends the new shipping module: a `Shipping_Method_Pickup` subclass + a `Warehouse_Store`
  over a yandex-shaped table + a `Map_Provider` (yandex) + a `Pickup_Point_Source`.
- **Test:** `tests/unit/YandexPilotFixtureTest.php` — asserts the fixture loads end-to-end
  through the new module **and** that the yandex installed-site contract strings are preserved
  (method IDs `yandex_delivery_express`/`yandex_delivery_other_day`, REST ns `wc-yandex-delivery`,
  warehouse table name, order-meta prefix, session key, cron hook). This is the **proof the
  abstraction actually fits the #1 reference plugin**, not a hypothetical.
- Per the S0 precedent (program-tracker §"Validation deviation"): this is an **in-repo
  fixture**, not a live yandex rewrite. It proves the architecture; live-data preservation is
  enforced at the yandex plugin's eventual rewrite via the data-preservation checklist (§8).
- Reuse note: extract the shared testable-resolver/WP-stub helper the program-tracker flagged
  (3rd fixture trigger) instead of copying `EdostavkaPilotFixtureTest` scaffolding again.

---

## 8. Data-contract preservation (release-blocking)

The yandex installed-site contracts are captured in
`docs-internal/migration/yandex-data-preservation-checklist.md` (companion deliverable). Those
strings feed `.autodev/INVARIANTS.md` and become guard candidates. The framework module
**must not** hardcode any of them — they are the yandex plugin's, preserved at its rewrite.
The S1 queue's guard task (`guard-yandex-contracts`) writes mutation-verified guards for them
so the loop can later touch the yandex contract zone autonomously.

---

## 9. Acceptance criteria (this spec)

- [x] Covers all five §7 areas, PVZ-map phased first, each with interface + extension points (§4).
- [x] Maps what moves from yandex's hand-rolled code into the shared module (§4 sub-points cite the yandex equivalents).
- [x] States platform-neutral rules carried from S0 (§3).
- [x] Specifies the yandex-fixture validation gate mirroring the edostavka pilot (§7).
- [x] The four §6 direction decisions are recorded as blessed (§0).
- Companion deliverables: the yandex checklist (§8) and the file-disjoint task queue (§6 → `.autodev/queue/pending/`).

## Related
- `docs-internal/platform-v2-direction-audit-2026-06-03.md` §7 — shipping sizing (scope source).
- `docs-internal/migration/yandex-data-preservation-checklist.md` — release-blocking contracts.
- `docs-internal/autodev-loop-runbook.md` — the loop this queue feeds (Planner role, file_set rule).
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — checklist format mirrored.
- `woodev/payment-gateway/` — architectural maturity reference.
- `woodev/shipping-method/` — the skeleton this spec extends.
