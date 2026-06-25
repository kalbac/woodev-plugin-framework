# Shipping Module — Decisions & Decomposition (s32 brainstorm)

> **Status:** program-level decisions LOCKED with operator (interactive brainstorm, 2026-06-25, s32).
> Resolves all 14 open operator questions from `SHIPPING-PLANS.md` §20. This document **supersedes
> the `{нужно дополнить оператором}` gaps** in the operator draft. The draft (`SHIPPING-PLANS.md`,
> repo root) remains the narrative/code-review source; this file is the authoritative resolution.
>
> **This is NOT an implementation spec.** It is the decomposition layer. Each sub-project below gets
> its own `brainstorm → spec → plan → implementation` cycle. No code until a sub-project is picked.

## Guiding principles (reaffirmed this session)

- **Framework = DRY mechanism + contract + hooks. Domain (carrier plugin) = carrier specifics.**
  The framework must not make domain/tariff decisions (corrected on §6).
- **Clean-break v2:** internal APIs free to break; installed-site **data** is release-blocking.
- **YAGNI:** do not generalize a one-carrier mechanism into the framework (warehouses §17, Pochta
  CMS-backend §18 both dropped on this basis).
- **Platform neutrality:** one settings mechanism serves WC today and WP/EDD plugins later.

---

## Decisions by section

### §15 — Settings page (ADR-worthy; was the blocking fork) ✅

**Two settings surfaces:**
1. **Global plugin settings (new):** menu **`Woodev > Настройки`**, appears when ≥1 Woodev plugin
   registers a settings page. Lives in the existing `Woodev` admin hub (alongside Лицензии, Плагины).
   - Structure: **page → tabs → (optional) sub-sections.** **Rule: 1 tab = 1 provider** — a carrier
     (`СДЭК`, `Почта`) OR a framework service (`DaData`). A multi-carrier plugin (§18) contributes
     **multiple** tabs. Sub-sections per tab, e.g. `СДЭК > Авторизация`, `СДЭК > Настройки формы заказа`.
   - Render: **one neutral React slot over the platform-neutral Settings-API via `woodev/v1`** (the
     proven wizard/License-page pattern). Same rendering for WP/WC/EDD.
   - **Registry** (`Settings_Page_Registry`, name TBD) aggregates tabs from plugins + framework services.
   - Settings-API schema gains **two grouping levels (tab / section)** — same primitive that groups
     `setting_ids` into wizard steps, reused.
2. **Per-method / instance settings (unchanged):** stay in the **WC Shipping Zones** method modal
   (tariff type, markup, packing). Zone-bound; framework may feed schema but the surface stays WC-native.

**Migration:** each plugin declares its **legacy option key** (`woocommerce_edostavka_settings`, …) and
Settings-API writes to that key. **Legacy settings URL → admin redirect** to the new tab (only the slug
changes; redirect is trivial).

### §8 — Order-form fields + state (the hardest point) ✅

Target model = **state-outside-DOM store + field registry + event delegation** (NOT re-binding patches).
Refinement to the draft: the shared part is the **core (store + registry + domain logic)** — what fields
exist, cascade region→city→address, validation, AJAX endpoints. Rendering = **two thin adapters** over one
core:
- **Classic adapter** (`[woocommerce_checkout]`): reads/writes store, renders DOM via delegation, syncs WC session.
- **Blocks adapter** (Gutenberg): reads/writes the same store, mounts React into block slots, syncs `wc/store/checkout`.

**Validation gating (A2, s32):** if a method requires a pickup point and none is selected, the field layer
**blocks order placement** with a clear error — classic + blocks. Part of the field contract.

### §11 — Block checkout ✅

**Classic first; blocks = mandatory fast-follow.** Core is designed block-ready from day one; blocks must
not break classic. Product cards already state "Checkout Blocks not supported" — no surprise to users.
Minimal must-work-in-blocks set: city field (with value source), region field, **pickup-point button + map
modal**, correct order-meta/session write. Pickup-point button+modal is the hard one (not covered by WC's
standard additional-fields API).
**Implementation reference (mandatory):** study WC address-autocomplete API
(developer.woocommerce.com/docs/features/address-autocomplete/) — near-ideal but limited to Address/Postcode
fields (we need Region/City) → use as reference, do not reuse as-is. (Same API resurfaces in §9.)

### §6 — Carrier API unavailable at checkout ✅

**Framework builds NO tariff fallback.** Default on failure: **hide the method** + log (if logging enabled).
Framework fires a **`do_action` hook** so a plugin/user can implement its own fallback. **Cache-as-fallback
rejected** (stale rate → order placed at a tariff that's gone by export time). The success-rate cache (perf)
stays — that's separate.

### §5 — Authentication + secrets ✅

- **Auth contract:** type (oauth / basic+token / token) + lifecycle (storage, refresh/expiry, invalidation
  on cred change) + optional **separate credential channel** (Pochta SOAP tracking).
- **`sensitive` flag** → masking in UI / API logs / REST / export — **always**, regardless of value source.
- **Optional `constant_name`** per sensitive option: if `defined()`, value comes from the wp-config constant,
  UI field is disabled with a hint, save **skips** it (never overwrites). Secret never touches the DB.
  Constant name is domain-supplied; mechanism is framework. (Endorsed as **better than DB encryption**.)
- Default (no constant) = plaintext in DB. **No mandated encryption.**

### §9 — Shared services registry + Dadata ✅

- Model: **framework-owned service, single config + single UI, owned by no plugin.** Plugins **declare**
  "I need suggestions on field X"; conflict resolved centrally. Kills the legacy crutches (Yandex's
  `WC_CDEK_SHIPPING_VERSION` version-check; Pochta's silent yield to CDEK).
- **Registry is extensible** — adding a future service must not require rewriting logic (services register
  via a common "service" interface: id, settings schema → own tab, field declarations, hook points).
- **Only service for now: Dadata / address autocomplete** (token+secret, per-field toggles state/city/address,
  index autofill, geo-IP city detection **inside this service**, cascade region→city→address). Settings tab
  `Woodev > Настройки > DaData`.
- **Two distinct registries** (draft conflated them): **field registry (§8)** = who attaches what to each
  checkout field; **shared-services registry (§9)** = framework services with one config. A service claims a
  field *through* the field registry.

### §13 — Tracking + canonical statuses ✅

- push+pull contract confirmed (webhook + cron over background-job, status mapping, history, domain actions
  `..._status_changed_from_{old}_to_{new}`).
- **Canonical delivery status set (~9):** `pending / created / in_transit / ready_for_pickup / delivered /
  returning / returned / failed / cancelled`. Carrier maps its statuses in. **Raw carrier label preserved**
  for display + **full history**. Per-canonical **configurable WC order status** mapping (`delivered` → WC
  `completed`).
- `ready_for_pickup` **is tracked** (merchant sees "in pickup point, waiting") but **no default email** for it
  (carrier notifies the recipient). "Status tracked" ≠ "we email on it" — emails decided in §14.
- §12 (export/shipment state machine) is **distinct** from §13 (delivery progress).

### §10 — COD / insurance / declared value ✅

Framework: **support flags** `supports_cod` / `supports_insurance` / `supports_declared_value` + **one
coordination hook** "available gateways for this method/pickup-point" (Yandex disables `cod` when the PVZ
doesn't support it). **Calculation details = domain** (commission, `payattr=4`, `insr-value`,
`total_assessed_price`, showing commission to the buyer).

### §17 — Warehouses / origin ✅ (subsystem DROPPED)

**No warehouse subsystem in the framework.** Multi-warehouse is a single-plugin Yandex feature (Yandex API
now returns warehouses; may even be removed from Yandex). Origin (sender point) is **ordinary plugin settings
fields** (city/address/contacts in the Auth/Sender section), not a subsystem. If "enter sender once for all
carriers" is ever wanted → add it as a new service to the §9 registry then, no rewrite.

### §7 — Pickup points + map ✅

- Frame confirmed: button → Backbone modal → select → AJAX → session → order meta; universal map, plugin
  writes no JS.
- **Modes:** **our map (Yandex.Maps) = primary**; **iframe = first-class supported** (Pochta requires it —
  no per-city points API; Yandex/CDEK as variety); **`<select>` = cheap optional fallback only** (used in
  Yandex due to test-env API limits), implement only if trivial, else drop.
- **Yandex.Maps key = framework map config (one place), NOT a §9 mutual-exclusion service** (it's shared
  config, not a monopoly service).
- Contract must address: performance (thousands of PVZ → load/clustering strategy), single nonce/caps on AJAX
  endpoints (JS↔PHP bridge), mobile modal.

### §18 — Multi-shipping + cross-plugin coordination ✅

- Multi-carrier in one plugin: formalize **only the registry seam** (N carriers like payment gateways; each =
  own tab per §15). No aggregator machinery built ahead — no such plugin planned in the foreseeable future.
- Coordination mostly already raised: Dadata → §9; competitor-notices → already a framework module (s28).
  Location-mapping glue (Pochta↔CDEK `city_code`→`postal_code`) stays **domain**, generalize only when a real
  aggregator needs it.
- **Pochta CMS-backend (`install-module`/`orders-public`/GUID):** **leave as Pochta domain legacy** — pure
  Russian-Post domain logic (`guid_id`/`guid_key`), used nowhere else. The earlier session's framing of it as
  "an aggregator skeleton" was a **misread** — corrected here. Preserve its data contract at migration; do not
  generalize.

### §12 — Shipment creation, waybill, barcode ✅

- **Export-side state machine** (distinct from §13): `not_created → created (remote-id + track) → label_ready
  → handed_over`, plus `export_failed`. After `handed_over`, §13 delivery statuses take over.
- **Auto-export:** configurable — "create shipment when order → status X" (**default OFF**). Manual export
  always available. **Idempotent** (remote-id present → skip).
- **Documents — "document source" abstraction with strategies** (carriers differ): `binary_content` (CDEK
  returns raw PDF bytes → framework wraps into a download response), `remote_url` (Yandex returns a direct
  link → framework redirects/proxies), `local_generated` (plugin generates the file; future). Plugin
  implements `get_document(type)`; framework handles download response, filename, "downloaded" flags. **PDFs
  not stored** — fetched/generated on demand.
- **Domain actions** (call courier, offer selection): framework provides admin-action plumbing (button →
  AJAX → domain handler); logic is domain. Print/courier gated by §4 support flags.
- **Cancellation (A3, v1):** cancel a created shipment at the carrier when the WC order is cancelled/refunded
  (domain action + framework plumbing + `cancelled` state). **Returns / reverse logistics → deferred** but NOT
  foreclosed — design the state machine + document sources + tracking so a return is "another shipment in the
  opposite direction," cheap to add later.

### §14 — Emails ✅

- Framework: **status-driven `WC_Email` base** + **placeholder registry** (`{order_number}`,
  `{tracking_number}`, `{tracking_url}`, `{carrier_name}`, `{pickup_point}`, `{delivery_date}`…) + **dedup**
  by meta flag.
- **Default ready-made emails** (each toggle + editable richtext template), tied to §13 statuses:
  «Передан в доставку»/tracking on `created`/`in_transit` (**ON**); «Доставлено» on `delivered` (**ON**);
  «Возврат / не доставлено» on `returned`/`failed` (**OFF**). No email for `ready_for_pickup`.
- Domain-specific emails (Yandex sharing-url) built on the same base.

### §4 — Plugin declaration scheme ✅

Two declaration kinds:
1. **Boolean support flags** (WC `supports`-style, on method/plugin): `supports_pickup_points`,
   `supports_tracking`, `supports_cod`, `supports_insurance`, `supports_declared_value`,
   `supports_label_printing`, `supports_courier_call`.
2. **Config-contracts** (not boolean): auth (type + optional tracking channel), map mode
   (our_map/iframe/select), status source (webhook/cron/both), field-needs (Dadata via field registry),
   method form (1-class-N-tariffs vs N-classes).

Plugin **type** is auto via `extends` (s27 removed `capabilities`). **Mechanism locked; final flag
names/defaults settled at first-plugin migration.**

### §19 — Data contract (release-blocking) ✅

**Per-plugin migration via Lifecycle** (operator decision). Each v2 plugin ships a `Woodev_Lifecycle`
`upgrade_to_X_Y_Z()` routine with an **explicit** mapping {legacy key → canonical framework field} — only the
plugin knows its fields, so it owns the migration; nothing is guessed. Framework defines canonical fields
(`chosen_pickup_point`, `tracking_number`, `remote_order_id`, `delivery_status`, `status_history`,
`customer_location`…).
Flagged for each plugin's `docs-internal/migration/<plugin>-data-preservation-checklist.md`:
1. migrate **existing** orders' meta too (in-flight orders) — batch via `Woodev_Background_Job_Handler`;
2. migration **idempotent**; safer **non-destructive** (copy to canonical, keep legacy a version as fallback)
   — decided per plugin;
3. verify **no external reader** of the legacy key (carrier webhooks, other plugins, theme, exports).
The draft's key tables (CDEK `_wc_edostavka_*`, Pochta `_wc_russian_post_*`, Yandex `_yandex_delivery_*`) are
the **starting reference**; full per-plugin verification at migration.

---

## Proposed sub-project decomposition + order

Each SP = its own brainstorm → spec → plan → implementation. Dependency-aware order:

**Phase A — foundation**
- **SP-1 Settings page slot (§15)** + the §4 declaration spine + the extensible §9 service-registry shell.
  *Blocking foundation; builds directly on the s31 Settings-API/wizard.* **Recommended first.**
- **SP-2 Auth contract + secrets (§5)** — needed by every carrier.

**Phase B — checkout core**
- **SP-3 Field layer core + classic adapter (§8)** — the hardest, highest value.
- **SP-4 Dadata service (§9)** — first shared service (needs SP-3 + SP-1).
- **SP-5 Map / PVZ (§7)** — our map + iframe + (opt) select (needs SP-3 + SP-1).

**Phase C — fulfillment**
- **SP-6 Rate calc + packing (§6 + box-packer)** — `get_rate_cost`, success-cache, packer, API-fail hook.
- **SP-7 Shipment export + documents (§12)**.
- **SP-8 Tracking + canonical statuses (§13)**.
- **SP-9 Emails (§14)** — needs §13.
- **SP-10 Orders admin page (§16)** — per-carrier React page; needs SP-7 + SP-8.

**Phase D — modernization**
- **SP-11 Blocks adapter (§11)** — second field-layer adapter, after classic proven.

**Phase E — validation**
- **Pilot migration: Yandex** (PVZ reference) → then CDEK → then Pochta. Validates the whole stack + the §19
  migration approach. Conformance audit vs the 3 reference plugins feeds this (next-session-prompt).

Cross-cutting reality (s27 audit): the current shipping skeleton is rich but **never validated by a real
plugin** — `admin/views/html-admin-shipping-method-status.php` ~30% stub; no label/export abstraction;
JS/CSS assets unverified; webhook handler not yandex-validated. These are absorbed by the SPs above + the
pilot migration.

---

## Deferred / open

- §16 ROI/charts — optional feature, not a v1 goal (purpose: show the merchant the plugin earns/saves).
- §7 `<select>` map mode — only if trivial, else drop.
- §9 future shared services (e.g. shared sender) — add to the registry when needed, no rewrite.
- Final §4 flag names/defaults — at first-plugin migration.

## Cross-cutting constraints (bake into EVERY sub-project)

Not "decisions" — hard project rules that apply across all SPs (recorded so no SP forgets them):
- **HPOS-safe order data:** all order meta read/write via `Woodev_Order_Compatibility`, never
  `get_post_meta()` (gotcha `hpos-order-meta-safety`). Every tracking/pickup/status meta touch.
- **i18n with Russian source strings:** avoid `_n()` (gotcha `russian-source-i18n-plural-n` — no ru catalog,
  Russian IS the source → wrong plural forms). Use count-neutral phrasing for status labels, emails, counts.
- **No Composer in shipped plugins:** runtime is hand-written `spl_autoload` + generated class-map; regenerate
  `woodev/class-map.php` after any new framework class (`bin/generate-class-map.php`).
- **Secrets:** `sensitive` masking in any new log/REST/export surface (§5).
- **Data contract:** never break installed-site keys without an explicit Lifecycle migration (§19).

## Not yet discussed — categorized (gap analysis, s32)

**(A) RESOLVED (s32):**
- **A1 Setup Wizard reuse → NO.** Shipping plugins handle their own onboarding; the framework wizard is not
  mandated for shipping. (It remains opt-in framework-wide; just not imposed on shipping plugins.)
- **A2 Checkout validation gating → YES, in SP-3 field core.** If a method requires a pickup point and none is
  selected → block order placement with a clear error (classic + blocks). Part of the field contract.
- **A3 Cancellation → v1; returns → deferred but architecturally ready.** Cancel a created shipment at the
  carrier on order cancel (domain action + plumbing + `cancelled` state) ships in v1. Reverse logistics (return
  shipment) is a second mirror lifecycle → deferred, BUT the §12 state machine + document sources + §13 tracking
  are designed so a return is "another shipment in the opposite direction" — cheap to add later on the same
  machinery (do not foreclose it in SP-7/SP-8 design).

**(B) Enough info to start — resolve at the relevant SP's own spec:**
- Estimated delivery date/time in the rate contract (`{delivery_date}` placeholder; срок доставки display) — SP-6.
- AJAX vs REST convention for checkout/PVZ-search endpoints (prefer `woodev/v1` REST for new code vs WC's
  admin-ajax checkout fragments) — SP-3/SP-5.
- Per-plugin debug/logging toggle + log source (largely exists in the framework) — SP-2.
- Test/sandbox environment toggle (Yandex `environment`) — likely domain — SP-2.
- Capabilities for settings/orders/export pages (`manage_woocommerce`) — SP-1/SP-10.

**Bottom line:** the architecture-level decisions are sufficient to START the program. SP-1 (settings slot)
needs none of the open items. The (A) items are small and fold into the relevant SP's brainstorm.

## Next step

Operator reviews this document → picks the **first sub-project** (recommended **SP-1 Settings page slot**) →
that SP enters its own brainstorm → spec → plan. No implementation until then.
