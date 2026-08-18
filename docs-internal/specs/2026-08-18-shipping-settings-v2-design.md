# Shipping settings V2 — the «Доставка» tab: Location / Fields / Map — design

> **Status: DESIGN APPROVED by the operator in the s79 brainstorm (18.08.2026), not implemented.**
> Implementation is a separate session (planned on Opus 5). Plan:
> `docs-internal/plans/2026-08-18-shipping-settings-v2-plan.md`. Board card: **#362**.
>
> Input document (BUILT / DECIDED / OPEN markup, measurements, traps):
> `docs-internal/specs/2026-08-18-location-and-field-settings-brainstorm-input.md`. Everything marked
> DECIDED there (D1–D11) stays in force and is not restated unless this design depends on it.
>
> Every claim about the codebase below was checked in s79 against `main` at `ed7f9f8`; every claim
> about WooCommerce was measured against WC **11.0.1** on the rig (`woocommerce.latest-stable`).

---

## 1. Goal

One store-level place where a shop owner shapes checkout behaviour for **every** Woodev shipping
plugin at once — provider choice, field presence/order, pickup-map behaviour — so that carriers
stop competing for the same option (D8: «чтобы все карьеры вели себя одинаково предсказуемо»).

Out of scope: the block-checkout adapter (SP-11), the fixed-locality picker UI (its own card),
new location providers, anything about rates.

## 2. Decisions taken in the brainstorm (s79) — the delta over the input document

| # | Decision | Rationale (operator's, 18.08.2026) |
|---|---|---|
| **S1** | **One tab «Доставка»** on `Woodev → Настройки` (the existing «Локация» tab renamed), with three sections: **«Локация» / «Поля» / «Карта»**. Not three tabs. | One screen shows the whole checkout behaviour; `field_mode` stays next to the provider it depends on. |
| **S2** | **Field type = the existing `field_mode`**, no new setting. Vocabulary: `typeahead` / `ajax-select2` / `related-list`. Admin label: «Тип поля НП/Регион». The CDEK plugin's three values map onto it (§4.1). **«Region gates settlement» is a consequence of `related-list`, not a separate mode.** | Two settings steering one axis would be a defect. Both operator notes — a select2 data adapter is needed; the related-list region is selectWoo over a preset list, only for providers that can enumerate — are already how the code behaves (§4.1). |
| **S3** | **«Подсказки для адреса» lives in «Локация»**, right under the provider block. Its availability is computed from what sits in the same section (active provider serves `address`, OR DaData credentials present). Blocked → `disabled` + reason (D11). | The cause of a block is visible on the same screen. Consequence: «Поля» holds NO suggestion-related option — only presence / visibility / order / required. |
| **S4** | **Postcode = one `select` with three values**: `show` / `hide for pickup methods` / `remove`. **Address = one `select` with two values**: `show` / `hide for pickup methods` (no `remove` — an address field is never removed by the framework). | Values name genuinely different mechanisms (T1); one control, honest labels. Address mirrors postcode so the two read the same. |
| **S5** | **Field-order preset applies to ALL shipping countries of the store** (`WC()->countries->get_shipping_countries()`), not to a fixed CIS list, not to "countries the location layer serves". On by default. Mechanism: `woocommerce_get_country_locale` (`priority`). | One switch — one predictable behaviour; per-country is WC's mechanism, not the setting's meaning. |
| **S6** | **The block checkout DOES honour country-locale `priority`** (measured, §5.4). Therefore the field-order preset is available on both checkouts and is NOT rendered disabled on the block checkout. | Closes T5 / OPEN 6 in the good direction. |
| **S7** | **Map section = three STORE options, none of which a carrier can override**: button placement (`rate` / `review`), write the point's address into the address fields (on), close the map after a pick (off). `refresh_checkout` stays a carrier decision. **Carrier constructor arguments for `replace_address` and `close_on_select` are removed** (clean-break, ADR-005). | «Карьер главный по умолчанию, магазин — там, где правило обязано быть общим»: buttons jumping between carriers and a map that closes for one carrier but not another are exactly that. «Иначе ансамбль разрозненных опций.» |
| **S8** | **Third-party checkout-field managers: read the FINAL assembled fields, restore what we own, note it in admin.** The framework re-asserts the settlement invariants (present + required) when a third-party filter changed them, and shows a status note on the tab. Other fields are left to the field manager. | «Я как разработчик плагина не гарантирую работу при использовании сторонних плагинов, но мне важно, чтобы функционал моего плагина был максимально стабилен, поэтому я оставляю за собой право переопределять значения, которые не являются дефолтными WC.» |
| **S9** | **Section visibility is derived from what plugins supply, not declared** — no new `supported_features` key (that array is WC-compat metadata: `hpos` / `blocks`, unrelated). Tab: any active `Shipping_Plugin`. «Локация»: any `needs_location_provider()` (as today). «Поля»: always while the tab exists. «Карта»: any plugin supplying a `Pickup_Handler`. **Granularity is per section**; options inside a section are disabled-with-reason, never hidden. | Reuses the existing `declare_needed()` pattern; the owner sees that a mechanism exists even when it cannot reach their checkout. |

## 3. The settings surface

### 3.1 Tab and sections

```
Woodev → Настройки → [Доставка]
  ┌ Локация ────────────────────────────────────────────────┐
  │ Провайдер локаций          [DaData ▼]                    │  BUILT (active_provider)
  │ <provider's own fields: token, secret …>                 │  BUILT (provider_fields)
  │ Тип поля НП/Регион         [Текст с подсказками ▼]       │  BUILT (field_mode), relabelled
  │ Подсказки для адреса       [x]                           │  NEW  (address_suggestions)
  │   ↳ disabled + reason when nobody serves `address`       │
  │ Локация по умолчанию       [Выкл ▼]                      │  BUILT (default_locality_policy)
  └──────────────────────────────────────────────────────────┘
  ┌ Поля ───────────────────────────────────────────────────┐
  │ Порядок полей RU/СНГ       [x] (on by default)          │  NEW  (field_order_preset)
  │ Поле «Страна»              [Показывать ▼ | Скрывать]     │  NEW  (country_field)
  │   ↳ disabled + reason unless the store ships to ONE country; block checkout: disabled + reason
  │ Поле «Регион»              [Показывать ▼ | Убрать]       │  NEW  (region_field)
  │ Поле «Адрес»               [Показывать ▼ | Скрывать для ПВЗ]           NEW (address_field)
  │   ↳ block checkout: disabled + reason (needs the classic-only JS layer)
  │ Поле «Индекс»              [Показывать ▼ | Скрывать для ПВЗ | Убрать]  NEW (postcode_field)
  │   ↳ `hide for pickup` value: block checkout → disabled + reason; `remove` reaches both
  └──────────────────────────────────────────────────────────┘
  ┌ Карта ──────────────────────────────────────────────────┐
  │ Кнопка выбора ПВЗ          [В строке метода ▼ | После списка методов]   NEW (pickup_button_placement)
  │ Записывать адрес пункта в поля адреса   [x]              │  NEW  (pickup_replace_address)
  │ Закрывать карту после выбора пункта     [ ]              │  NEW  (pickup_close_on_select)
  └──────────────────────────────────────────────────────────┘
```

Section visibility: S9. All labels are Russian user-facing strings through the framework text
domain; setting ids are English (`snake_case`).

### 3.2 Setting inventory

| Section | Setting id | Type / control | Default | Availability rule |
|---|---|---|---|---|
| Локация | `active_provider` | BUILT | — | — |
| Локация | `field_mode` | BUILT; label → «Тип поля НП/Регион» | `typeahead` | BUILT gate: `related-list` / `ajax-select2` only for `CAPABILITY_LIST` providers |
| Локация | `address_suggestions` | bool / checkbox | `true` | enabled iff the chain resolves SOME provider for `address` in at least one served country (active provider declares `address`, or the bundled DaData is configured); otherwise `disabled` + reason «Выбранный провайдер не отдаёт адреса, а учётные данные DaData не заполнены» |
| Локация | `default_locality_*` | BUILT | — | — |
| Поля | `field_order_preset` | bool / checkbox | `true` | always |
| Поля | `country_field` | select: `show` / `hide` | `show` | `hide` enabled iff `count( get_shipping_countries() ) === 1`; block checkout → `disabled` + reason |
| Поля | `region_field` | select: `show` / `remove` | `show` | always (classic: unset; block: locale `hidden`) |
| Поля | `address_field` | select: `show` / `hide_for_pickup` | `show` | block checkout → `disabled` + reason |
| Поля | `postcode_field` | select: `show` / `hide_for_pickup` / `remove` | `show` | `hide_for_pickup` unavailable on the block checkout (control stays enabled, that OPTION is filtered out with the reason in the description); `remove` reaches both |
| Карта | `pickup_button_placement` | select: `rate` / `review` | `rate` | always |
| Карта | `pickup_replace_address` | bool | `true` | always |
| Карта | `pickup_close_on_select` | bool | `false` | always |

Option storage: the existing store-level settings handler pattern (`Woodev_Abstract_Settings`
subclass, option name `woodev_{service}_{setting}`). **The «Локация» ids keep their current option
names** (`woodev_location_active_provider` etc.) — renaming the tab must not rename stored options
(installed-site data contract, ADR-005). New sections get their own handler(s) under new service
ids; see the plan.

### 3.3 «Disabled with reason» — a new capability of the settings surface

D11 needs a per-field `disabled` state with a description. Today `Field_Schema::from_handler()`
emits `description`, `show_if`, `constant_managed`, `sensitive` — no `disabled`. Add:

- PHP: `Woodev_Control` gains `disabled: bool` and `disabled_reason: string`; `Field_Schema`
  emits `disabled` + `disabled_reason` (reason falls back into `description` for older clients).
- React (`src/components/control-field.js`): a disabled field renders its control with the
  `disabled` attribute and the reason as the description, following the `constant_managed`
  read-only precedent. A disabled field is **not sent on save** (its stored value is untouched).
- Per-OPTION filtering (postcode `hide_for_pickup` on the block checkout) is done server-side by
  narrowing `options` and appending the reason to the description — the same "clamp on read"
  discipline `get_field_mode()` already uses for a saved value that is no longer offered.

Where a stored value is no longer allowed (e.g. `country_field=hide` after a second shipping
country was added), the READ side clamps to the safe value (`show`) — never the write side, exactly
like `field_mode` today.

## 4. Behaviour

### 4.1 Field type (`field_mode`) — mapping the CDEK vocabulary

| CDEK today («Выпадающий список городов») | `field_mode` | Customer sees |
|---|---|---|
| (c) «Не использовать» | `typeahead` | plain inputs with suggestions on region / settlement / address, per provider capability |
| (a) «Вкл» — select2 over the whole country | `ajax-select2` | settlement is a select2 whose remote source is our `/suggest`; region is auto-filled from the picked settlement (kept as an input; the CDEK degradation of region to a suggestion-less text is NOT reproduced) |
| (b) «Связанный поиск» | `related-list` | region = the WooCommerce state `<select>` (selectWoo-enhanced by WC itself, exactly like countries with preset states) fed by the provider's enumerated regions via `woocommerce_states`; settlement blocked until a region is picked, then a region-scoped list |

Facts that make both operator notes moot (BUILT, `location-select-modes.js`): the select2 modes
never use select2's native `ajax` — `fetchEntries()` is wired as a custom `ajax.transport` and the
`{key,label,level,record}` responses are merged into a lookup map (an adapter, same idea as the
CDEK plugin's); `related-list` and `ajax-select2` are offered only to providers declaring
`CAPABILITY_LIST`, so DaData (query-only) gets `typeahead` alone. No ajax-driven region for DaData.

### 4.2 Address suggestions on/off

`address_suggestions=false` removes the `address` level from the served levels the checkout config
hands to the browser (`Checkout_Config::build_location_block()` → `levels[country].address=false`
for every country), so `location-cascade.js` binds nothing to `*_address_1`. Nothing else changes:
the chain, `within`, the pickup filing key are all settlement-based. When the option is
`disabled` (nobody serves `address`) the effective value is `false` regardless of what is stored.

### 4.3 Field presence, visibility, order — ONE policy object, TWO instruments

A new framework-level singleton (working name `Checkout_Field_Policy`, `woodev/shipping-method/checkout/`)
reads the «Поля» settings once per request and applies them through exactly two WooCommerce seams:

**Instrument A — `woocommerce_get_country_locale` (reaches BOTH checkouts).**
For every shipping country of the store, the policy contributes per-field `priority`, `hidden`,
`required`:

| Setting | Locale contribution |
|---|---|
| `field_order_preset=true` | `priority`: country 10 · state 20 · city 30 · address_1 40 · address_2 50 · postcode 60 (Страна > Регион > Город > Адрес > Индекс) |
| `region_field=remove` | `state.hidden=true`, `state.required=false` |
| `postcode_field=remove` | `postcode.hidden=true`, `postcode.required=false` |
| settlement invariant (D2/T3) | `city.required=true` — re-asserted last (see 4.4) |

**Instrument B — `woocommerce_checkout_fields` at a LATE priority (classic only).**
For `region_field=remove` / `postcode_field=remove` the field is `unset()` in **both** `billing`
and `shipping` sections, so it leaves the DOM (T1: the value must never reach the order). On the
block checkout the same setting acts through Instrument A's `hidden` (the block form does not render
hidden core fields — measured, §5.4), which also keeps the value out of the order.

**Classic-only, JS-driven (never through PHP filters):**
- `address_field=hide_for_pickup`, `postcode_field=hide_for_pickup`: `location-cascade.js` /
  `pickup-mount.js` toggle a CSS class + `required=false` on the field row whenever the selected
  shipping method is pickup-type, and restore on any other method. Values stay in the DOM: an
  address written by `pickup_replace_address` still reaches the order.
- `country_field=hide`: CSS-hide the country row and keep it filled with the single shipping
  country (T1: the value reaches the order). Blocked unless exactly one shipping country.

Removal is **never** done in JS; hiding is **never** done by unsetting (T1, T2). Every contribution
is computed from the setting values, then applied over the FINAL array (T4) — the policy hooks
after WooCommerce's own defaults and after typical field-manager plugins (priority `PHP_INT_MAX - 10`
on both seams; the exact number is the plan's to fix, the rule is «after everyone who has an
opinion»).

### 4.4 Third-party field managers (S8)

The policy's late callbacks compare the FINAL array against the framework's own invariants and
restore only those:

| Invariant | Restore | Note |
|---|---|---|
| `*_city` exists (D2) | re-insert WooCommerce's default `city` definition into the section it was removed from | with the field-manager's other changes intact |
| `*_city` is `required` (T3) | `required=true` on both seams | a carrier that needs otherwise overrides AFTER us — the framework is not responsible for that override |
| our own `hidden` / `priority` contributions | re-apply | a field manager that re-sorted fields loses against an explicit preset |

Every restoration is recorded (`Checkout_Field_Policy::get_overrides()` → `[field, what, by whom if
detectable]`) and surfaced on the «Доставка» tab as a status note under the «Поля» section
description — same instrument as `apply_default_locality_status_note()`. Nothing else is touched:
labels, placeholders, classes, extra fields belong to the field manager.

JS side (T6): every bind first checks the target exists in the DOM and is visible; an absent
region collapses the settlement/address scope to the country (§5.1 of the input); a hidden field
is bound but not focused/scrolled to.

### 4.5 Map options (S7)

| Setting | Consumer today | Change |
|---|---|---|
| `pickup_button_placement` | `Checkout_Config::resolve_pickup_slot_placements()` — framework default `rate` → filter | insert the store setting between the default and the filter, exactly as the docblock there already announces («→ a future framework-level store setting →») |
| `pickup_replace_address` | `Pickup_Handler::$replace_address` (constructor arg, default `true`) | constructor arg REMOVED; the handler reads the store setting; `replaceAddress.enabled` in the JS config follows it |
| `pickup_close_on_select` | `Pickup_Handler::$close_on_select` (constructor arg, default `false`) | constructor arg REMOVED; `selection.close` follows the store setting |
| `refresh_checkout` | `Pickup_Handler::$refresh_checkout` | UNCHANGED — a carrier fact (price moves with the point or not) |

`woodev_pickup_slot_placements` stays as the last-resort filter (extension-hook rule). Removing
the two constructor arguments breaks the fixture plugin's construction call
(`tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php:736`) and any
production plugin that passes them — allowed on the v2 line (ADR-005), listed in the plan.

## 5. Facts this design rests on (checked in s79)

### 5.1 BUILT seams reused
- `Settings_Page_Registry::register_service()` + `Settings_Provider` / `Settings_Section` — tab
  and sections (`woodev/settings-page/`).
- `Location_Settings` (`woodev/shipping-method/location/class-location-settings.php`) — the
  handler owning `active_provider`, `field_mode`, `default_locality_*` + provider fields.
- `Location_Provider_Registry::register_settings()` (`:1420`) — builds the tab today; the
  «read-side clamp» pattern (`get_field_mode()`).
- `Shipping_Plugin::needs_location_provider()` → `declare_needed()` — the section-visibility
  pattern S9 extends.
- `Checkout_Config::resolve_pickup_slot_placements()` — placement precedence with the store
  setting slot already reserved.
- `Pickup_Handler` flags (`class-pickup-handler.php:185–225`), `applyAddressReplacement()` in
  `pickup-mount.js`.
- `Checkout_Handler::register()` hooks `woocommerce_checkout_fields` per plugin (`:191`) — the
  new policy is store-level and lives beside it, not inside it.
- `Blocks_Handler::is_checkout_block_in_use()` (`woodev/handlers/blocks-handler.php:77`).
- `Field_Schema::from_handler()` + `src/components/control-field.js` — the surface that gains
  `disabled` (§3.3).

### 5.2 `supported_features` is NOT the D9 seam
`normalize_supported_features()` (`woodev/bootstrap.php:239`) knows exactly `hpos` and
`blocks.cart` / `blocks.checkout` — WooCommerce compatibility declarations. The input document's
hint to use it for section visibility is retracted; S9 derives visibility instead.

### 5.3 What the block checkout does NOT read
`CheckoutFields::get_core_fields()` (WC 11.0.1, `src/Blocks/Domain/Services/CheckoutFields.php`)
hard-codes the core address fields; the block form never sees `woocommerce_checkout_fields`. Hence
Instrument B is classic-only by construction, and every JS-driven option is disabled-with-reason
on the block checkout until SP-11.

### 5.4 What the block checkout DOES read (measurement closing T5)
`CartCheckoutUtils::get_country_data()` (`src/Blocks/Utils/CartCheckoutUtils.php:356–370`) maps
every country's `WC()->countries->get_country_locale()` entry to the client, renaming `priority` →
`index`; the shared checkout bundle (`wc-cart-checkout-base-frontend.js`) merges the per-country
locale into the field list and sorts `(e, t) => e.index - t.index`, and applies `hidden` →
`required=false` + not rendered. So `woocommerce_get_country_locale` is the one instrument that
reaches both checkouts for **order, hidden, required, label**.

## 6. Data contracts (release-blocking, ADR-005)
- Existing option names under the «Локация» service are unchanged by the tab rename.
- New options are new keys; no migration.
- `field_mode` values (`typeahead` / `related-list` / `ajax-select2`) unchanged.
- The `woodev_pickup_slot_placements` filter signature unchanged.
- REST: `woodev/v1/settings` gains new fields in the schema; no route changes.

## 7. Error handling and degradation
- A stored value that is no longer allowed is clamped on READ to the safe value (`show` for the
  field selects; `false` for `address_suggestions` while nobody serves the address level), never
  rewritten.
- A third-party removal of the settlement field is restored + noted (S8); a third-party removal
  of any other field is respected.
- On the block checkout the JS-driven options are inert AND disabled in admin with the reason;
  Instrument A options work.
- If `get_shipping_countries()` is empty (store not configured), the preset contributes nothing
  and `country_field=hide` is disabled with the reason.

## 8. Testing (what the plan must produce)
- Unit (Brain Monkey): the policy's locale contribution per setting matrix; the late-filter unset
  in both sections; the invariant restoration with a simulated field-manager mutation (settlement
  removed / made optional); read-side clamps; the map settings' precedence over defaults; the
  availability rules with the block-checkout detector mocked both ways.
- Jest: `disabled` rendering in `ControlField`; disabled fields excluded from the save payload;
  pickup-hide toggle on method change; country-hide keeps the value; DOM-presence checks.
- Integration (wp-env): the tab renders three sections given the fixture plugin (which supplies
  both a location provider and a `Pickup_Handler`); the country locale carries the preset
  priorities for a shipping country; `region_field=remove` leaves no `shipping_state` in the
  assembled checkout fields.
- Rig, classic checkout (`/classic-checkout/`, order per `CURRENT-STATE.md`): the preset order,
  region removed, postcode hidden on pickup method, button placement flip, close-on-select,
  replace-address off. Rig, block checkout (`/checkout/`): field order follows the preset; region
  is not rendered under `remove`. Every rig claim with a control run.

## Related
- Input: `docs-internal/specs/2026-08-18-location-and-field-settings-brainstorm-input.md`
- Program map: `docs-internal/specs/2026-06-25-shipping-module-decisions.md`
- ADR-005 clean-break policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md`
- Issues: #362 (this), #353, #337 (`refreshAddressLock()` comment cites the wrong rule — fix in the plan), #274/#323 (placement history)
- Gotchas: `rig-checkout-url-is-the-block-checkout`, `block-checkout-reads-country-locale-not-checkout-fields` (s79)
