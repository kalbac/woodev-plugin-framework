# §8 Checkout Field Layer — Design (SP-3 in the shipping decomposition)

> **Status:** design LOCKED with operator (interactive brainstorm, 2026-07-06, s42).
> Scope = **core + classic adapter** of the shipping checkout field layer. Blocks adapter (SP-11),
> map/modal (SP-5/§7) and the DaData suggest source (SP-4/§9) are explicitly **out of scope** — the
> core is designed so they plug in later without a rewrite.
>
> Naming note: this is **§8** from `docs-internal/specs/2026-06-25-shipping-module-decisions.md`
> (= "SP-3 Field layer core + classic adapter" in that doc's decomposition). It is unrelated to the
> already-shipped "SP-3 field validation" on the **settings page** (s39). Here = **checkout fields**.
>
> Supersedes nothing; extends the existing `Woodev\Framework\Shipping\Checkout\*` skeleton
> (`@since 1.5.0`, "never validated by a real plugin"). New code = `@since 2.0.2`, VERSION not bumped.

---

## 1. Problem & grounding

The checkout field layer is "one of the most painful points in real shipping plugins" (operator). The
pain is concrete and was grounded on the **real CDEK plugin** behavior during the brainstorm:

- CDEK turns the native "Населённый пункт" (`billing_city`) into a **selectWoo/select2 autocomplete**:
  empty on load, options fetched **as the user types** (`Мос` → `Москва`) — no preset list.
- City suggestions are scoped by the **selected region** if a region field is used, else by the
  **selected country**.
- **Region conflict:** for CIS countries (RU/BY/KZ/UZ) WooCommerce has **no preset state list**, so the
  carrier occupies `billing_state` freely. For FR/DE/US, WC **does** render its own state `<select>`,
  which the carrier's own DB doesn't recognize → a conflict over who owns the field.
- WooCommerce's own `country-select.js` **re-renders** `billing_state` (text↔select) on country change,
  and the shipping-methods block re-renders on `updated_checkout` — the classic source of "my bindings
  got blown away, let me re-bind again" patches.

### Existing skeleton (reused, not greenfield)

| Piece | What it is today | §8 disposition |
|---|---|---|
| `Checkout_Fields` | VO registry of flat descriptors (`id/type/label/required/sanitize_callback/validate_callback`) | **Extend** the descriptor (new generic keys) |
| `Checkout_Handler` | Classic server pipeline: `woocommerce_checkout_fields` inject → `woocommerce_checkout_process` validate → `woocommerce_checkout_order_processed` save via `Woodev_Order_Compatibility` (HPOS-safe, meta key = field id); forward hooks namespaced by plugin prefix | **Extend** (enhance-in-place inject, conditional-required, config emit) |
| `Shipping_Plugin::get_checkout_handler(): ?Checkout_Handler` | Opt-in seam, `null` default; `register()` called when non-null (`class-shipping-plugin.php:217-219`) | **Reuse** as the opt-in seam |
| `Pickup_Checkout_Handler` + `checkout.js` + `pickup-map.js` | Pickup button + modal + map (jQuery, AJAX `selectPoint`) | **Do NOT extend** — this is SP-5/§7 territory that predates the split; §8 only defines the seam it will mount into |

---

## 2. Locked decisions (brainstorm, s42)

1. **Frontend model = WC-native fields + external JS store + event delegation.** Region/city/address stay
   standard `woocommerce_checkout_fields` (WC renders them natively, theme styling kept). A new client
   **store holds canonical state OUTSIDE the DOM**, binds via **delegation on `document`** (never per-element
   re-binding), and **restores values + re-runs cascade on every `updated_checkout`** and after WC's own
   `country-select.js` re-render. No re-binding patches.
2. **Field contract = generic mechanism + thin preset helpers.** The framework ships a generic field with
   `depends_on` + `source` + conditional `required` + `takeover_condition` — **no domain types**. Region/city/
   address are plugin-declared instances. Thin optional preset constructors (e.g. `Dependent_Select`) remove
   boilerplate but carry **no domain data**.
3. **Field `id` = the WC checkout field key.** An id that already exists in the WC checkout (`billing_state`,
   `billing_city`, `billing_country`) is **enhanced in place**; a new id is **added**.
4. **`source` has two kinds:** `options` (enumerated list, e.g. region — full list filtered by country,
   rendered up front) and `suggest` (remote typeahead by query `q`, e.g. city — empty until the user types).
   Same seam, different `source_kind`. DaData (SP-4) is another `suggest` source.
5. **`depends_on` = nearest present ancestor, may be a native field the framework doesn't own.** If the plugin
   registers a region field: `billing_city.depends_on = billing_state`; if not: `billing_city.depends_on =
   billing_country`. The store just observes whatever id is named, native or ours. Plugin decides which fields
   exist; framework honors the declared `depends_on`.
6. **Field-ownership conflict = domain `takeover_condition` predicate.** `set_takeover_condition(callable)`;
   framework calls it with `{country}` → `true` = our source owns the field (swap to our select/autocomplete),
   `false` = leave WC's native field untouched. CDEK: `fn($c) => in_array($c, ['RU','BY','KZ','UZ'])`. The
   framework provides the seam; the carrier owns the policy. **Mirrored to the client as a resolved
   per-country boolean map** — the server evaluates the predicate across the (finite, ~250) `WC()->countries`
   list at emit time and ships the map, so the client never runs PHP. (The callable is mirror-safe here
   precisely because its input domain is finite and enumerable.)
7. **Transport = `woodev/v1` REST, single generic controller.** `GET woodev/v1/shipping/checkout/{plugin_id}/
   field-source/{field_id}?country=..&parent=..&q=..` routes to the field's `source` callback server-side.
   Public read (`permission_callback` returns true) + nonce for CSRF, per-IP rate-limit, filterable timeout.
   Consistent with all new framework code (settings/wizard/license/account on `woodev/v1`); the blocks adapter
   reuses the same endpoint.
8. **A2 gating = conditional-required condition-spec + thin pickup preset.** A field's `required` may be a
   `bool` OR a **condition-spec** — the same flat, mirror-safe grammar shipped for settings `show_if` in s40
   (`{state, operator, value}`, operators `=`/`!=`/`in`/`not_in`), evaluated over checkout state (incl.
   `chosen_shipping_method`) by a **mirrored PHP↔JS evaluator** (fail-closed on unknown, like s40). A raw PHP
   callable is deliberately **not** used for `required` — it can't be mirrored to the client (the s40 lesson:
   a condition must return **data**, not an opinion). "Pickup required when a pickup method is chosen" is one
   instance: `{ state: 'chosen_shipping_method', operator: 'in', value: [ ...pickup_method_ids ] }` (the method
   ids are domain data supplied by the plugin). Server-authoritative in `Checkout_Handler::validate` (blocks
   placement via `wc_add_notice`); the client store mirrors it (blocks the Place-order button + shows the error).
   Arbitrary server-only logic that can't be expressed as a spec stays in the existing `validate_callback`
   (blocks authoritatively, just without proactive client gating).
   **Robustness (Codex HIGH #2, s42 — operator-chosen):** because a fat-fingered spec (`operator: 'inn'`) would
   make BOTH the mirrored client AND the server evaluate `required = false` — silently placing an order with an
   empty **mandatory** pickup — the runtime fail-open is NOT trusted for fulfillment-critical fields. Two guards:
   (a) **register-time spec validation** — `Checkout_Fields::add()`/`Field` validate the condition-spec shape and
   fire `_doing_it_wrong()` on a malformed spec or an operator outside the closed set `{=,!=,in,not_in}`, so a
   typo is caught in dev/CI, never reaching production; (b) an **independent server-side pickup backstop** —
   the shipping method declares `requires_pickup` (a boolean method-level flag, NOT the mirrorable spec), and
   `Checkout_Handler` blocks placement when a `requires_pickup` method is chosen and the pickup field is empty,
   **regardless of the condition-spec**. Runtime eval stays fail-open (`false`) only for non-fulfillment fields
   (never trap a paying customer on a broken optional-field spec).

---

## 3. Architecture — three layers over two adapters

### 3.1 CORE (framework-agnostic, block-ready, vanilla JS — no jQuery)

- **`Checkout_Fields` registry** with the extended descriptor (below).
- **Server config emitter** → JS: the registered fields + cascade edges + required/takeover rules + endpoint
  URLs + nonce, localized once per handler.
- **REST source controller** (`woodev/v1`, generic) — §2 decision 7.
- **Conditional-required & takeover evaluators** — authoritative PHP + a mirrored JS evaluator (same discipline
  as the s40 `show_if` PHP↔JS mirror: string compare, fail-closed on unknown).
- **The JS store** (vanilla): canonical state outside the DOM + cascade engine + validation evaluator +
  restore. **This is the reusable core both adapters share.** No jQuery, no WC-blocks import — portable.

### 3.2 CLASSIC adapter = `Checkout_Handler` (extended)

Server: `inject()` maps descriptors into `woocommerce_checkout_fields` (add or enhance-in-place); WC renders
natively; the existing validate/save/HPOS-meta pipeline stays — **except `save()` skips enhanced-native fields**
(Codex HIGH #3): a field whose `id` is a native WC checkout/address key (`billing_*`/`shipping_*`) is already
persisted by WooCommerce as a core order property, so re-writing it as our own order meta would double-store and
drift after edits/refunds/imports. `save()` persists only genuinely-new field ids (e.g. the pickup point code).
Client (jQuery glue only): delegated binding,
`updated_checkout` restore, select2 for `suggest` fields, takeover swap on country change, mount the pickup
slot, block "Оформить заказ".

### 3.3 BLOCKS adapter (SP-11, fast-follow — NOT built in §8)

Mounts React into WC Checkout block slots over the **same store + same REST**. §8 only guarantees the core is
block-portable (vanilla store, no classic-only assumptions leaking into core).

---

## 4. The field descriptor (contract)

Canonical **normalized** shape (extends `Checkout_Fields::normalize()`; array is authoritative, a fluent
`Field` builder + presets are ergonomic sugar over it):

```php
[
  'id'                 => 'billing_city',       // = WC checkout field key: exists → enhance in place, new → add
  'type'               => 'select',             // text|select|hidden|...
  'label'              => 'Город',
  'section'            => 'billing',            // WC checkout-fields section to inject into (default 'order')
  'required'           => true,                 // bool OR condition-spec array (mirrored s40 grammar)  (A2)
  'depends_on'         => 'billing_state',      // parent field id (native or ours); null = root
  'source'             => [ $carrier, 'cities' ],   // callable fn(array $context): array  | null
  'source_kind'        => 'suggest',            // 'options' (enum) | 'suggest' (typeahead by q) | null
  'takeover_condition' => null,                 // callable fn(array $context): bool  | null — server-resolved to a per-country map; only meaningful when enhancing a native field
  'sanitize_callback'  => null,                 // fn($raw)         (falls back to wc_clean)
  'validate_callback'  => null,                 // fn($value,$field): bool|WP_Error
]
```

- **`source` callback contract:** `function cities( array $context ): array` where `$context` carries parent
  values + `country` + (for `suggest`) `q`. Returns a list of `['value' => .., 'label' => ..]`. For `suggest`
  the callback filters by `q`; for `options` it ignores `q`.
- **Serialization to JS** never includes the PHP callables — only `has_source`, `source_kind`, `depends_on`,
  `required` (resolved to a rule the mirror can evaluate, or a boolean), the endpoint URL, and the per-country
  takeover map. Secrets/callables stay server-side.

### Thin presets (sugar, zero domain)

- `Dependent_Select::create($id, $parent_id)` → `type=select` + `depends_on=$parent_id`.
- `Pickup_Field::create($id)` → a hidden field holding the chosen point code + conditional-required bound to
  "a pickup method is chosen" + marks the DOM slot/anchor where SP-5 mounts the button+modal. (The **map is
  not built here**; only the field + gating + slot.)

---

## 5. Runtime flows

- **Cascade:** parent `change` (delegated) → store writes parent, **clears the child value** → REST call to the
  child's source with `{parent, country}` → repopulate the child `<option>`s **from data** (no handler
  re-binding) → restore the child value if still valid.
- **Takeover:** country `change` → WC `country-select.js` re-renders `billing_state` → store (delegated) catches
  it → evaluates the per-country takeover map → `true`: swap the field to our source/select2; `false`: leave
  WC's native field. Re-applied after every such re-render (this is exactly what the external-store model buys).
- **Suggest:** typing in a `suggest` field → debounce → REST `?q=..&country=..&parent=..` → options.
- **A2 gating:** `required` condition-spec evaluated against `{chosen_shipping_method, ...}` by the mirrored
  evaluator. Server: `Checkout_Handler::validate` on `woocommerce_checkout_process` adds a `wc_add_notice('error')`
  → WC blocks placement (authoritative). Client: store runs the same spec → blocks the Place-order button + shows
  the error next to the control. Both sides required (server = truth, client = UX).

---

## 6. REST controller

- Route: `GET woodev/v1/shipping/checkout/(?P<plugin_id>[\w-]+)/field-source/(?P<field_id>[\w-]+)`.
  `plugin_id` disambiguates which registered handler owns the field (field ids like `billing_city` are not
  plugin-unique). The emitted JS config carries the fully-built URL per field.
- **Auth:** public read (`permission_callback` → true) + WP REST nonce (`X-WP-Nonce`) for CSRF; guest checkout
  must work (the `rest-endpoint-not-for-browser-cookie-auth` gotcha is about browser-navigation screens, not
  JS-`fetch` with a nonce — this is the latter, so REST is fine).
- **Resolution:** the controller reconstructs the plugin's checkout registry and invokes the field's `source`
  with the sanitized `{country, parent, q}` context. **Constraint:** field + source registration must run early
  (`init`/plugin load, **not** gated on `is_checkout()`), so the registry exists on a REST request.
- Per-IP rate-limit + filterable timeout (mirrors the s26 `FETCH_TIMEOUT` lesson for outbound carrier calls).

---

## 7. Cross-cutting constraints (from the decisions doc — bake in)

- **HPOS-safe order meta** — already via `Woodev_Order_Compatibility::update_order_meta()` in `save()`.
- **No `_n()`** — Russian source strings; count-neutral phrasing for any labels/errors.
- **No Composer in shipped plugins** — regenerate `woodev/class-map.php` (`bin/generate-class-map.php`) after
  every new framework class.
- **Data contract** — per-field order-meta key = the plugin-supplied field id (unchanged); no new installed-site
  contract string is coined by the framework.
- **Early registration** — see §6 constraint.

---

## 8. Testing strategy

- **Unit (PHP):** descriptor `normalize()` with new keys (defaults, callable-vs-null); conditional-required
  evaluation; takeover evaluation + per-country map emission; `inject()` **add vs enhance-in-place**; REST
  controller routing + context sanitization + source dispatch; config emitter shape (no callables/secrets leak).
- **Unit (JS):** store cascade (parent change clears+repopulates child), restore on re-render, validation
  evaluator **mirrors** the PHP one (parity fixtures), suggest debounce.
- **Integration:** REST `field-source` endpoint (guest + nonce); `woocommerce_checkout_process` gating blocks a
  missing conditional-required field.
- **Browser e2e (operator/self, `:8888`, classic):** on the «Карьер» fixture — region→city cascade repopulates;
  country takeover swaps (CIS vs FR); placing an order without the required pickup is blocked with a clear error;
  happy path saves the values HPOS-safe. Screenshots to the operator before merge.

## 9. Out of scope / deferred

- **Map + modal** (pickup button UI, clustering, iframe/select modes) = **SP-5 / §7**. §8 gives only the field +
  gating + mount slot.
- **DaData suggest source** = **SP-4 / §9** — plugs into the `suggest` seam; no §8 change needed.
- **Blocks adapter** (React over the store) = **SP-11 / §11** — core is block-ready; adapter built later.
- Multiple shipping plugins fighting over the **same** native field (e.g. two both enhancing `billing_city`) —
  last-registered wins + log; not a real scenario today, noted not solved.

## 10. Process

`brainstorm (done)` → **`writing-plans`** → **Codex critic on the plan** (architectural, pre-code, per s34) →
subagent-driven implementation (fresh agent, two-stage spec + code-quality review) → **Codex critic on the impl
+ re-critic own fixes** (no self-certify) → **operator/self browser e2e on `:8888` (classic)** before merge.
`@since 2.0.2`, VERSION unchanged.

## 11. Codex critic hardening (s42, folded into the design pre-code)

Adversarial Codex pass on the design/plan (threadId `019f34cc-4399-7180-8e2c-3830c170168b`). Dispositions:

- **HIGH #2 A2 fail-direction** → §2 decision 8 above (register-time spec validation + independent
  `requires_pickup` server backstop; runtime fail-open only for non-fulfillment fields). *Operator-chosen.*
- **HIGH #3 native-save collision** → §3.2 above (`save()` skips enhanced-native ids).
- **HIGH #1 guest REST hardening** → the controller **strictly normalizes** `country` (whitelist against
  `WC()->countries` keys → `''` if unknown), `parent`/`q` (`wc_clean` + a length cap, e.g. 128) BEFORE they reach
  the source callback; best-effort per-IP transient rate-limit (acknowledged weak vs proxies/IPv6 — a bar-raiser,
  not a wall); the source-response contract is `{ value, label }` with `label` rendered client-side via
  `textContent` (never `innerHTML`) and `esc_html`'d server-side → closes the label-XSS (LOW). A code comment on
  `permission_callback => __return_true` states the endpoint is intentionally public-read; a future *sensitive*
  source must add its own auth.
- **HIGH #4 multi-plugin conflict** → register-time `_doing_it_wrong()` when two handlers claim the same native
  field id; documented limitation (full arbitration is out of scope — no such deployment exists). Last-registered
  wins, logged.
- **MED parity** → the JS mirror MUST match PHP exactly on the edges: empty `conditions: []` → **`false`** (guard
  against JS `every([])===true`); `in`/`not_in` with a non-array `value` → **`false`**; bool/`'0'`/`''`/missing
  coercion identical. Shared parity fixtures test both sides.
- **MED takeover event** → re-apply takeover on WC's **`country_to_state_changed`** event (fired AFTER WC
  re-renders `billing_state`), not a raw `change` — removes the re-render race. Takeover application is
  additionally gated on the carrier field being active (a country-only map that says "takeover=true" is only
  *applied* when our field is in play). The US-state-code-for-tax caveat is the **domain predicate's**
  responsibility (CDEK's `in [RU,BY,KZ,UZ]` deliberately never takes over a WC-state country) — framework docs
  warn; it does not police it.
- **MED conservative merge** → enhance-in-place overrides only descriptor-provided keys; WC's `validate` array is
  preserved/appended, never replaced.
- **MED early registration** → field + source registration is wired on **`init`** (never `is_checkout()`-gated),
  with an integration test that hits the REST route **without** rendering checkout.
- **LOW options-context** → a root `options` field's `source` is called at inject **with the current country
  context**, not `{}` (avoids emitting a full/irrelevant list).
- **LOW value preservation** → the store keeps the prior value across a native↔custom swap and restores it if a
  matching option reappears (freeform-with-no-match is kept in the store, not silently dropped).
