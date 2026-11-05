# «Доставка в пределах города» (range shipping 2.0.0) — design

**Date:** 2026-11-05. **Status:** draft — written for the coordinator and a critic of another lineage; every gap this spec filled itself is a
**Provisional** item `PC1…PCn`, repeated in §12. Nothing marked Provisional is decided.
**Target:** the same release `2.0.0` of «Расчёт стоимости доставки за расстояние» (plugin id `range_rate`) as
[2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) (called **v2 spec** below; its D1–D16 are binding and
are not reopened here). Two repos change: this framework (the store-wide restriction itself) and `kalbac/woocommerce-range-shipping` (the
settings and the switch).

**Evidence labels:** **READ** (code or document read this session), **UNVERIFIED** (reason given — a rig or fixture step proves it),
**Provisional** (`PCn`). `fw:` = this repo's `woodev/`; `plugin:` = `kalbac/woocommerce-range-shipping` at `45df7aa`.

## 0. The request, and decisions already made

Operator's words, 05.11.2026 (binding):

> Режим «В пределах города». Есть магазины, у которых доставка только в пределах своего города или плюс близлежащих городов.
> Обычно это доставка еды. Нам нужен такой режим, и работать он должен на весь сайт, а не для конкретного метода. В основных
> настройках плагина должна быть опция «Доставка в пределах города» с ясным объяснением, для чего эта опция и что она делает.
> При её включении появляется возможность добавлять список городов, минимум один город, максимум не ограничен. Города выбираются
> из списка и просто добавляются в общий список. Если город только один, то так же есть опция «Скрывать поле населённый пункт»:
> при её включении на чекауте поля «Населённый пункт» нет, оно всегда предзаполнено указанным городом из настроек. Если городов
> больше одного, то на чекауте список только из этих городов. Магазинщику при включении этой опции явно говорим, что если они
> используют другие службы доставки, например СДЭК или Почта РФ, то их покупатели не смогут выбрать город вне списка. Поле
> «Адрес» остаётся обязательным, иначе не доставить заказ.

| # | Decision | Source |
|---|---|---|
| K1 | The feature ships **in 2.0.0** | operator 05.11.2026 |
| K2 | A list item is **any settlement** at DaData's city/settlement level (Химки, пос. Коммунарка, Зеленоград), not only a city | operator |
| K3 | The settings live on a **new sub-tab «Доставка в городе»**; sub-tabs become **Склады · Сервисы · Доставка в городе · Дополнительно** | operator (named after the first brief said «Город») |
| K4 | The option is a **plugin setting that acts store-wide**, for every shipping method | operator |
| K5 | Classic checkout is complete in the release; block-checkout gaps may follow it **only if classic is complete** | operator rule |

## 1. Goal, scope, non-goals

**Goal.** A food-delivery shop (one city, or a city and its neighbours) switches one option on, lists its places, and from that moment
no buyer can place an order for an address outside them — on any shipping method, on the classic and the block checkout — while the
buyer's experience gets *simpler*: a hidden field (one place) or a short list (several).

| In scope | Non-goals (named once, not built) |
|---|---|
| Settings sub-tab, storage, validation, warnings (§3) | Radius / polygon zones, per-method place lists (the framework's per-method `City_Limit` stays as is) |
| A **framework** store-wide «allowed localities» restriction, checked server-side on every path that takes a location (§2, §5) | Restricting the **My Account** address form, the order-edit screen, REST/API order creation (PC9) |
| Classic + block checkout behaviour: hide / prefill / list / returning customer / billing-only shops (§4) | Postcode derivation from the place (DaData gives none at city level) |
| Licence behaviour (§7), range-method interaction (§6), coexistence with other Woodev carriers (§8) | A cap on the number of places (the operator: unlimited) — only a rendering rule, PC4 |

## 2. Where the restriction lives — plugin-only vs framework

The operator's «на весь сайт» means the restriction must reach everything that reads a customer location. READ — every one of those
paths is **framework code shared by all Woodev carriers**, and there is **one** location registry and service per store, not one per plugin:

| Path | Where (READ) | Why a plugin cannot do it alone |
|---|---|---|
| The checkout widget config (`mode`, `popular`, `defaultLocality`, `regionFieldRemoved` …) | `fw: checkout/class-checkout-config.php` `build_location_block()` | The list/hide behaviour is a *client config*; a plugin would have to patch another module's output |
| Suggest / list / select REST routes, fleet-wide `woodev/v1/location/*` (no per-plugin namespace) | `fw: rest-api/class-location-controller.php` `perform_suggest()`, `handle_select_request()` | One public route serves CDEK, Yandex, Post, Ozon and range alike |
| The customer's saved record, its staleness gate, the default-locality lazy trigger | `fw: location/class-location-service.php` `gate_chain()`, `get_customer_record()`, `resolve_default()` | A record from before the switch-on must be *ignored* for every consumer |
| Server-side submit checks (classic `woocommerce_checkout_process`, Store API `validate_order` / `update_order_from_request`) | `fw: checkout/class-checkout-handler.php` `guard_custom_settlement()`, `handle_store_api_validate_order()` | Every carrier plugin registers its own `Checkout_Handler`; the check must not depend on which one is installed |
| Availability of a Woodev method | `fw: class-shipping-method.php:1699` `City_Limit::permits()` | The per-method limit already runs here; the store-wide one is the same seam |

**Proposal (PC1).** A generic framework feature **«allowed localities»**; the range plugin is its first and only *source*.

```
 range plugin (settings, licence) ──registers──▶ filter woodev_location_allowed_localities
                                                        │
                    framework: Location_Service::get_allowed_localities(): ?Allowed_Localities
                                                        │
      ┌───────────────┬──────────────────┬──────────────┴───────┬───────────────────────┐
  customer-record   REST suggest/       checkout config       submit validation      Shipping_Method
  gate + default    list/select         (list / hide)         (classic + Store API)  availability
```

*Rejected — plugin-only.* The plugin would filter `woocommerce_checkout_fields`, `woocommerce_get_country_locale` and the REST
responses itself, fighting the framework widget that rewrites the same fields (the field-policy class documents that it runs at
`PHP_INT_MAX - 10` precisely so that nobody gets the last word after it — `fw: checkout/class-checkout-field-policy.php:84`). Two owners
of one field is the drift that class was written to end. *Rejected — a framework-owned setting:* the operator wants the option in the
plugin's own settings, and the framework must not grow a product switch that only one plugin sells.

**The seam (names Provisional, PC1):**

```php
namespace Woodev\Framework\Shipping\Location;

final class Allowed_Localities {
    public static function from_records( array $settlements, bool $hide_settlement_field ): self; // Location_Record[], LEVEL_SETTLEMENT
    public function records(): array;
    public function is_single(): bool;
    public function hides_settlement_field(): bool;               // is_single() && the flag
    public function permits( ?Location_Record $settlement ): bool; // provider-agnostic, see below
    public function permits_text( string $country, string $city, string $region = '' ): bool; // posted text, the fallback check
}
// read side (memoised per request; null = no restriction):
Location_Service::get_allowed_localities(): ?Allowed_Localities
// write side — the one filter a plugin hooks; it returns null/unchanged when it has nothing to say:
apply_filters( 'woodev_location_allowed_localities', ?Allowed_Localities $current ): ?Allowed_Localities
```

**Matching is provider-agnostic.** The list is stored as DaData records (§3), but the shop's active location provider may be another
one (a carrier's own list). `permits()` is true when the keys are equal, **or** country equal and the normalised settlement name
equal and — when both sides know it — the normalised region equal. This is the rule of the `across_providers` re-resolution that
`City_Limit` already uses (`fw: location-service` `reresolve_stranded_record( $stored, true )`, #1185), reusing
`Location_Service::normalize_place_name()`. A buyer-side option is *rendered* from the active provider's own record, obtained by that
same re-resolution at config-build time (result cached per list hash + provider id; an entry that does not resolve to exactly one
place is dropped from the buyer's list and flagged to the merchant, §3.5). **Provisional PC2.**

**Only one source is honoured.** If a second plugin ever hooks the filter, the first non-null result wins and the second gets a
`_doing_it_wrong()`; a merge of two restrictions has no defined meaning and nobody asked for it. **Provisional PC3.**

## 3. Settings (plugin)

### 3.1 Fields and storage

New sub-tab **«Доставка в городе»** (section id `city_mode`) between «Сервисы» and «Дополнительно»: one more
`Settings_Section` in the provider list of `Range_Plugin::get_tab_settings_providers()` (`plugin: includes/class-range-plugin.php`),
a handler `Woodev\Range_Shipping\City_Mode\City_Mode_Settings extends \Woodev_Abstract_Settings` modelled on `Warehouse_Settings`
(READ, `plugin: includes/warehouses/class-warehouse-settings.php`). Options (namespace `range_rate`, so `woodev_range_rate_*`):

| Setting | Type / control | Option | Autoload | Notes |
|---|---|---|---|---|
| `city_mode` | boolean, toggle | `woodev_range_rate_city_mode` | yes | the master switch, default **off** |
| `city_mode_cities` | string, `records-table` | `woodev_range_rate_city_mode_cities` | no | one column «Населённый пункт» = `location-picker`, `level` `settlement`, `provider` DaData (like the warehouse address column, D3). Stored value = the control's JSON list of rows `{ "city": "<Location_Record JSON>" }`, the whole record kept, `raw` dropped on save exactly as `City_Limit::encode()` does (READ) |
| `city_mode_hide_field` | boolean, toggle | `woodev_range_rate_city_mode_hide_field` | yes | «Скрывать поле «Населённый пункт»», default **off** (PC5) |

Reuse ladder: the `records-table` and `location-picker` controls exist (F1, framework 2.0.26 — READ `fw: settings-api/class-control.php:118,128`),
`Records_Table::decode()` reads the rows, `City_Limit`'s record codec supplies the compact record form; **no new control is needed**.
Autoload: the switch and the flag are read on every request that renders a checkout; the list is read only when the switch is on
(it can be large), so it is non-autoload.

`city_mode_cities` and `city_mode_hide_field` carry `show_if city_mode = yes` (ADR-008 grammar: one scalar controller — READ).
**The hide toggle cannot be shown "only when exactly one city"**: ADR-008 has no row-count operator and its controllers are scalar
(READ `adr/008-conditional-fields-operator-set.md:28`). So the toggle is visible whenever the mode is on, its text says «Работает, когда
в списке один город», and the server applies it only when the list has exactly one place. **Provisional PC5** (alternative: a new
`count` operator in ADR-008 — rejected as a framework change for one toggle).

### 3.2 Validation (server, on Save)

| Rule | Message (Russian, for a novice) |
|---|---|
| Mode on and the list empty | «Добавьте хотя бы один населённый пункт — иначе ни один покупатель не сможет оформить заказ.» |
| A row is not a DaData settlement-level record with a name and a country | «Выберите населённый пункт из подсказок.» (row prefix «Строка N:» is the table's own) |
| The same place twice (same key, or same name + region + country) | «Этот населённый пункт уже в списке.» |
| Mode **off** | the list is *kept* and not validated — a merchant who switches off and on again does not retype it |

The browser is not trusted: the checks run in `validate_row()` / `validate_values()` like `Warehouse_Settings`, and the record is re-read with
`Location_Record::from_array()` (an invalid record throws and is refused). **No upper bound** is enforced (operator). A runtime
reading of "mode on, list empty" (only a crafted write can produce it) is treated as **mode off** — a shop must never be locked out
by a bad row.

### 3.3 The page text

```
 Доставка в городе ─────────────────────────────────────────────────────────────
 [x] Доставка в пределах города
     Включите, если вы доставляете заказы только в своём городе или ещё в нескольких
     ближайших — например, доставка еды или цветов. Покупатель сможет выбрать на
     оформлении заказа только населённые пункты из вашего списка. Это действует на
     весь магазин сразу — для всех способов доставки, а не для одного.

   ┌─ Важно ──────────────────────────────────────────────────────────────────┐
   │ Если в магазине есть другие службы доставки — например, СДЭК или Почта   │
   │ России, — их покупатели тоже не смогут выбрать город вне вашего списка.  │
   │ Включайте режим, только если вы действительно не отправляете заказы      │
   │ дальше.                                                                  │
   └──────────────────────────────────────────────────────────────────────────┘

   Населённые пункты
   Покупатель сможет выбрать только их. Выбирайте из подсказок — подойдёт город,
   посёлок или район, например Химки, пос. Коммунарка, Зеленоград.
   ┌────┬─────────────────────────────────────────────────────────────┬────┐
   │ 1  │ г Москва                                                    │ 🗑 │
   │ 2  │ г Химки, Московская обл                                     │ 🗑 │
   └────┴─────────────────────────────────────────────────────────────┴────┘
                                                       [ + Добавить город ]

   [ ] Скрывать поле «Населённый пункт»
       Работает, когда в списке один город: покупателю не нужно его выбирать, поля
       на оформлении заказа не будет, город подставится сам. Поле «Адрес» остаётся —
       без адреса заказ не доставить.
```

Empty table text: «Добавьте хотя бы один населённый пункт — город, посёлок или район города.» The «Важно» box is the operator's
requirement that the merchant is told **explicitly**; it is shown whenever the switch is on (a framework info block, not a tooltip).
Buyer-facing and merchant-facing strings are Russian msgids in the plugin (admin = Russian is allowed, `DOCS-SCHEMA` i18n rule); the
buyer strings added to the **framework** are English msgids with a Russian catalogue entry (storefront rule #567). Both listed in §4.5.

### 3.4 What the code reads, and when it registers

`City_Mode\City_Mode` (plugin) hooks `woodev_location_allowed_localities` **only when all hold**: the switch is on, the list is non-empty,
and `License_Gate::is_in_force( Range_Plugin )` (§7). It builds `Allowed_Localities::from_records( $list, $hide_flag )`. Nothing else in
the plugin touches the checkout; the framework does the rest. The framework option layer is never written (the v2 spec's rule: the
plugin does not change the shop's shared location settings).

### 3.5 Live warnings on the settings page (not stored flags)

Computed on render, like `apply_default_locality_status_note()` (READ `fw: location-provider-registry`), never persisted:

* a list place the active provider cannot re-resolve to exactly one place (§2) → «Населённый пункт «X» не найден службой подсказок, которую
  использует магазин, — покупатели его не увидят. Выберите его заново.»;
* the shop's «Локация» default locality (policy `fixed`) lies outside the list → «Город по умолчанию в настройках «Локация» не входит в этот
  список и не используется»;
* licence not in force → «Лицензия не действует, поэтому ограничение по городам сейчас выключено. Введите или продлите ключ.» (§7);
* the active location provider has no key / the layer is inactive (`Location_Service::is_active()` false) → «Не указан ключ службы подсказок —
  ограничение по городам не может работать» and the restriction stays **off** (a restriction the buyer cannot satisfy must not lock the shop).

## 4. Checkout

The server is the authority on every row below; the client only presents.

### 4.1 One place, field hidden (`is_single() && hide flag`)

| Aspect | Behaviour |
|---|---|
| The customer's record | `Location_Service::resolve_default()` returns the allowed place **before** consulting the store's policy (the same machinery as the `fixed` default, READ `resolve_fixed_default()`, but never written to the shared setting). It is stored through `Customer_Location_Store::set( $record, false )` — **explicit**, not implicit — so address suggestions scope to it (below) |
| Settlement field (`billing_city` / `shipping_city`) | Not shown; value = the place's name; **still posts** (PC6). Classic: the row is hidden by the framework's visibility mechanism (JS + CSS class, as `address_field=hide_for_pickup`/`country_field=hide` already are, READ `checkout-field-policy.php` header), **not** `unset()` — an unset field's value never reaches the order and the framework's own invariant `*_city` exists and is `required` (`restore_invariants()`, READ) would put it back. Block: `woocommerce_get_country_locale` `city.hidden = true` (Instrument A reaches both checkouts, measured on WC 11.0.1 per the class docblock — READ) |
| Region / state | Not shown either, value derived from the place (`wc_state_code_for_record()`, backwards-fill is the framework's existing behaviour). A visible region the buyer could change against a fixed city is a contradiction. Where WooCommerce has no states for the country (RU) it stays what it is today (PC7) |
| Postcode | **Untouched**: shown or removed per the shop's own «Поля» setting, required per WooCommerce's locale. DaData gives no postcode at city level, so it cannot be prefilled (PC8) |
| Address (`*_address_1`) | **Stays visible and required.** DaData address suggestions are scoped to the place: the controller's `perform_suggest()` already turns the chain's settlement record into `Location_Scope::within( $record, 'address' )`, which the DaData provider sends as a `locations` filter with `restrict_value` (READ `class-dadata-provider.php:1002-1018,1106`, `class-location-controller.php:2105`). Forcing the record into the chain is therefore enough — no new provider code |
| Country | Untouched; the place's country must equal the posted one (§4.4) |
| Billing + shipping | Both sets get the same treatment (§4.3) |

### 4.2 Several places

| Aspect | Behaviour |
|---|---|
| Settlement field | A choice of **only the listed places**, placeholder «Выберите город» (msgid "Choose your city"). Up to **25** places: a plain `<select>` with every option preloaded in the checkout config (key `allowed`, wire shape of `popular`: `{ key, label, level, record }`, READ). More than 25: the stock searchable list, but its `/suggest` answers come **locally from the list** (substring over the labels, no provider call); so there is no size cap, only a rendering rule (PC4) |
| Field mode | Forced at config-build time to the list/searchable mode — **without writing** the store setting. `typeahead` ("Text with suggestions", free text, never validated against a provider — READ `guard_custom_settlement()` docblock) cannot enforce anything, and `allow_custom_settlement` is ignored while the restriction is on |
| Region | Hidden, derived from the picked place — the places may span regions (Москва + Химки), and a region picker would contradict the list (PC7) |
| Address | Locked until a place is chosen (existing cascade: the address level waits for its settlement parent — READ), then suggestions scoped to the chosen place as above; picking another place clears street/postcode when the shop's `clearAddressOnChange` is on, otherwise the framework's current behaviour |
| Nothing chosen yet | The cart-page quote follows the v2 spec §6.1 (no street → no price beyond what the settlement point gives); with several places and none chosen there is no destination, so no rate |

### 4.3 Billing and shipping sets, billing-only shops

READ: the cascade attaches to `billing` only when `wc_ship_to_billing_address_only()`, to both otherwise
(`Checkout_Handler::location_target_sections()`); the section that currently decides delivery is resolved by `Address_Target`
(`settlement_field_id_for_section()`, `guard_custom_settlement()`).

* **UI:** both sections that exist get the same list / hidden field / forced value. In a billing-only shop (the CIS case: shipping goes to the billing address) only the billing set exists and *is* the destination.
* **Validation:** run against the section that currently decides delivery (`Address_Target`), never the other one — the same reason `guard_custom_settlement()` documents (the non-active set has no live cascade).
* **Forcing (hidden single place):** the server writes the place into **every** city/state/country field the posted data contains (billing, shipping, or both), so an order never carries a half-forced pair.
* Known cost, accepted (PC10): a payer who lives elsewhere and orders for someone in the city cannot type their own billing city while the shipping set exists, because the billing city is restricted too. The alternative — leave billing free whenever a separate shipping set exists — needs the active-section rule at *render* time (the buyer toggles «Доставить по другому адресу» in the browser), which is client state the server cannot read before submit; not built.

### 4.4 Server validation on submit (never trust the client)

| Path | Check | On failure |
|---|---|---|
| Classic, `woocommerce_checkout_process` (the framework's `guard_custom_settlement()` slot) | Hidden single place: **overwrite** the posted city/state/country with the allowed place (ignore what was posted). Otherwise: the delivery section's settlement must be allowed — by the customer's chain record (`permits()`) and the posted text must equal that record's `settlement_record_value()`; **no chain record** (cached page, stale JS, hand-built form) → fall back to `permits_text()` on the posted country + city (+ region) | `add_error()`: «Мы доставляем только в: Москва, Химки. Выберите населённый пункт из списка.» (msgid "We deliver only to: %s. Please choose your city from the list.") |
| Block, `handle_store_api_validate_order()` + `woocommerce_store_api_checkout_update_order_from_request` (the pattern at `checkout-handler.php:2529`, `RouteException` 400 for a missing pickup point) | Same decision on the order's delivery address; for a hidden single place, set the order's billing + shipping city/state/country before the check | `RouteException` 400, same sentence |
| Cart: Store API customer update / classic cart calculator (`handle_calculated_shipping`) | The same `permits()` on the address being quoted; hidden single place forces it | No destination → no rates |

The country check is part of `permits()`: a place of another country than the posted one does not match. A multi-country list shows
the buyer only the places of the chosen country; none → the sentence above with an empty list is replaced by «В выбранную страну доставка
не осуществляется» (PC11, minor).

### 4.5 Returning customer with a saved other city

| Saved state | Result |
|---|---|
| Chain record (session / user option) outside the list | Treated as **absent**, exactly like a stale record (`is_customer_record_stale()` rule c, new): `gate_chain()` drops it and every deeper level, `get_customer_record()` falls through to `resolve_default()` (hidden single → the place) |
| WooCommerce's own saved city text (profile / session customer), no record — `customer_saved_city()` / `resolve_saved_city_entry()` (#1075) | Used only if it resolves to an allowed place; otherwise ignored. **Several places:** the field is empty with the placeholder and one line under it: «Прежний город «Тула» не входит в зону доставки — выберите город из списка.» (msgid "The saved city “%s” is outside our delivery area. Please choose a city from the list.") **Hidden single place:** replaced silently by the place |
| Saved street / postcode of the rejected city | **Cleared** (prefill returns `''` for `address_1`, `address_2`, `postcode`, through `handle_checkout_get_value()`): a Tula street inside a hidden Moscow field is a silent wrong address (PC12) |
| A previously saved address in My Account | Untouched (PC9) |

### 4.6 What a returning buyer sees after the merchant switches the mode **off**

Nothing special: the gate stops dropping records; the chain, if still present, is simply valid again.

## 5. Framework changes (the work behind §2–§4)

Files are existing ones unless marked new.

| Unit | What |
|---|---|
| `woodev/shipping-method/location/class-allowed-localities.php` (new) | The value object and `permits*()` (§2); pure, unit-tested |
| `class-location-service.php` | `get_allowed_localities()` (filter, memoised per request, `null` while the layer is inactive); `is_customer_record_stale()` rule c; `resolve_default()` precedence; `customer_saved_city()` path guard |
| `rest-api/class-location-controller.php` | `/suggest` with `level=settlement`: answered from the list, no provider call; `level=address`: if no `within`, and exactly one place → that place as parent, with several → empty; `/list?level=settlement`: the allowed records; `/select`: a settlement or deeper record outside the list → 422 `woodev_location_outside_allowed` (the chain is not touched) |
| `checkout/class-checkout-config.php` | `build_location_block()` gains `allowed` (list or `null`), `hideSettlement`, forced `mode.settlement`, forced `allowCustomSettlement=false`, `regionFieldRemoved=true`, and the three buyer strings through `location_i18n_strings()` / the `woodev_location_i18n` filter (READ, line 845) |
| `checkout/class-checkout-handler.php` | `guard_allowed_localities()` beside `guard_custom_settlement()`; forcing of the hidden single place; `handle_checkout_get_value()` prefill rules (§4.5); the Store API pair (§4.4) |
| `checkout/class-checkout-field-policy.php` | `locale_contribution()` / `checkout_fields_contribution()`: `city.hidden` and `state.hidden` while the restriction hides them; `restore_invariants()` keeps `required` on `city` and must **not** re-insert a field this policy deliberately keeps hidden-but-present (it does not: hidden ≠ removed) |
| `class-shipping-method.php` (near line 1699) | `is_available_for_package()` also asks `Location_Service::get_allowed_localities()`: a Woodev method is unavailable outside the list, hidden reason `outside_allowed_localities` — this is how the restriction reaches **every Woodev carrier**, independent of their own `City_Limit` (the two are ANDed) |
| `src/**` (checkout-blocks bundle, classic `location-cascade.js`) | Select rendering, hidden rows, the rejected-city line, the block-checkout equivalents (§4.1–§4.2, F4/F5) |

## 6. Interaction with the range method

* The method still prices by distance (v2 spec §2.3, unchanged). Outside-the-list addresses never reach `rate_package()`: the destination
  is forced in (hidden) or constrained to the list, and the framework's `is_available_for_package()` hides the method otherwise (§5).
* `Destination_Point_Resolver` is untouched. With a hidden place the explicit chain record is a DaData settlement record, so the
  **cart-page quote** (no street yet) takes the v2 spec §2.3 step (b): the settlement point, precision `approximate`, and the §6.2 notice
  «Стоимость указана примерная…» shows until the buyer types the address.
* **Active provider ≠ DaData** (a carrier's own list serves the shop's widget): the chain record is then that provider's; the resolver
  already re-geocodes through DaData in that case (v2 spec §2.3 «DaData ownership»). Nothing new.
* `Range_Checkout_Handler` keeps declaring the three `source_location()` fields; the restriction is not a field of the plugin.
* **Defence in depth, not built (PC13):** a second availability check inside `Range_Shipping_Method`. The framework check in §5 already
  covers it; a duplicate is an invented safeguard.

## 7. Licence gate (PC14)

Standing rules: the licence gate is the default, no invented safeguards, no automatic outbound call without a licence (v2 spec §2.6).

| | Restriction **OFF** without a licence | Restriction **ON** without a licence |
|---|---|---|
| The plugin's own methods | hidden (framework) | hidden (framework) |
| Other carriers (СДЭК, Почта…) | work normally, buyers can order anywhere those carriers deliver | still limited to the list — the shop's whole delivery shrinks because *this* plugin's licence lapsed |
| Merchant's surprise | none: the shop behaves as if the plugin were absent | high: orders from other cities silently fail on carriers that have nothing to do with this plugin |
| Consistency with "no licence → no checkout offer" | yes, a feature of the product stops with the product | the one feature that keeps acting on other vendors' methods after the licence ends |
| Abuse | none | none |

**Provisional choice: OFF without a licence** — `City_Mode` registers the filter only when `License_Gate::is_in_force()`; the settings page
shows the notice in §3.5; settings stay editable (the gate never covers settings pages, READ `class-license-gate.php` docblock). The
claim refresh is the framework's (`request_claim_refresh()`), so a renewed licence re-enables the restriction on the next request. If the
operator prefers ON, the change is one condition in `City_Mode` and one sentence in §3.5.

## 8. Coexistence with other Woodev plugins (CDEK, Yandex, Почта, Ozon, …)

They share the one registry and service, so the restriction applies to them with **no code in those plugins**. Conflict cases:

| # | Case | Resolution |
|---|---|---|
| 1 | The shop's «Локация» default locality (`fixed` / geoip) is another city | Ignored while the restriction is on (an out-of-list default is dropped by the same gate); live note on the «Локация» section is out of scope, the plugin's own tab shows it (§3.5) |
| 2 | Field mode `typeahead` (free text) | Forced to list/searchable at config-build time (§4.2); the store setting is not rewritten, so switching the mode off restores the merchant's choice |
| 3 | `allow_custom_settlement` on | Ignored while the restriction is on |
| 4 | Region field mode `related-list` / carrier-injected states (`inject_related_list_states()`) | Region hidden in city mode; the injector's list is irrelevant; states derived from the place (PC7) |
| 5 | Pickup-point methods that write the point's address into the order (`Pickup\Address_Target`, READ) | A point in a city **outside** the list would fail the submit check with a confusing message. Intended: the point list is scoped to the chosen place (the pickup map takes the city), so no foreign point is offered. **UNVERIFIED** — a rig step per carrier; if a point can still be outside, the pickup handler must check `permits()` before copying the address (PC15) |
| 6 | The method has its own `City_Limit` (include / exclude) | ANDed with the store-wide list. A method whose own list is entirely outside the store list is never available; no warning is built (PC16) |
| 7 | Another plugin vendors an older framework copy | Irrelevant: the loader runs the highest copy (READ `class-framework-resolver.php`); `Range_Plugin` bundles ≥ the release that contains this feature (§10), so the feature is always present when the plugin runs |
| 8 | The range plugin is deactivated | The filter is gone → no restriction; the options stay |
| 9 | Non-Woodev shipping methods (flat rate, free shipping, local pickup, a third-party carrier) | They are not asked anything; the **checkout-level** enforcement of §4.4 holds for them, because the destination they quote is the address that passed it. A request that builds an order without the checkout (REST/API, admin) is not restricted (PC9). `woocommerce_package_rates` stripping is **not** built (PC17) |

## 9. Block-checkout gaps allowed after release (K5)

Release blockers on **both** checkouts: the server enforcement (§4.4, §5 rows for REST, handler, Store API, service gate), the forced
value of a hidden single place on the order, the licence behaviour, the settings page. Classic is **complete** only when additionally the
classic client work (F4) is done: preloaded `<select>`, hidden rows, rejected-city line, billing-only shops, returning customer.

If — and only if — F4 is complete, these block-checkout items may follow the release (each already has a safe fallback, because the
server rejects everything outside the list):

| Gap | Fallback until it ships |
|---|---|
| Preloaded `<select>` in the block locality chooser | The stock searchable list with locally filtered suggestions (§4.2 > 25 behaviour) — fully restricted |
| Hiding the settlement field in the block | Field **visible, prefilled and read-only** with the place; value forced on the order |
| The rejected-city line | Silent: the saved value is dropped, the placeholder shows |
| Hiding the region field in the block | Region shown, derived and read-only |

**UNVERIFIED (rig, card F5):** whether the block's address form clears a `hidden` field's value on its own, so that the Store API request
arrives without the city. The design does not depend on the answer — the server writes the place into the order regardless
(§4.4) — but the fallback column above is what to ship if hiding turns out not to work.

## 10. Cards

`F` = framework repo (kalbac/woodev-plugin-framework), `C` = plugin repo. Numbers continue the v2 spec's (F1, C1–C13). Issues are created
by the coordinator when the spec is approved. The release carries a **framework version bump**: the first release after 2.0.26 (name
Provisional, PC18); the plugin then bumps its bundled `woodev/` and loader `framework_version` together (the existing rule).

| Card | Scope | Depends | Size | Critic |
|---|---|---|---|---|
| **F2** | `Allowed_Localities` + `Location_Service` gates (record gate, `resolve_default()` precedence, saved-city guard, `get_allowed_localities()` + filter) + `Shipping_Method` availability. Pure unit tests incl. provider-agnostic matching, empty/odd lists | — | M | Codex, after merge |
| **F3** | Server enforcement: REST (`suggest` local answers, `list`, `select` 422, address-parent rule), classic submit (`guard_allowed_localities`, forcing), Store API pair, cart calculator, billing-only, prefill rules (§4.5). Tests per path incl. a forged form with no chain record | F2 | L | Codex, mandatory (security-shaped: a bypass is the failure) |
| **F4** | Classic client: `Checkout_Config` keys, select / searchable rendering, hidden rows, rejected-city line, strings + catalogue. UI Kit has nothing to add | F3 | M | rig check by the worker + Codex |
| **F5** | Block client: locality chooser select, `city.hidden` / `state.hidden` locale contribution, the UNVERIFIED hidden-value probe, fallbacks of §9 | F3 (F4 for the strings) | M | Codex; may slip past release by §9 |
| **C14** | Plugin: `City_Mode\City_Mode_Settings` (sub-tab, three options, validation, texts of §3), `City_Mode\City_Mode` (filter registration, licence gate), live warnings, recorded DaData fixtures for Химки / пос. Коммунарка / Зеленоград (UNVERIFIED: that all three are settlement-level), tests | F2 (interface only: develop against the §2 signatures) | M | Codex |
| **C15** | Plugin: range-method rig tests (cart quote with a hidden place, outside-list hide), docs (`docs/CURRENT-STATE.md`, product page text, materials package), Russian catalogue entries | C14, F4 | S | worker self + coordinator |
| **C16** | Rig acceptance (§11) classic + block, report with screenshots on disk | all | M | operator reads the report |

**Waves.** **W-A:** F2 ∥ C14 (disjoint repos; C14 codes against the signatures in §2). **W-B:** F3. **W-C:** F4 ∥ F5 ∥ C15. **W-D:** C16.
This runs inside the v2 spec's W5 slot: C13 (e2e, materials) waits for C16, because the product text must describe this tab.

## 11. Acceptance on the rig

Run on the WordPress rig with DaData, the range method active, a second carrier active (a flat-rate zone and, when available, СДЭК), WC
current and current−1. **Each row on classic and on block.** A row passes only when the *order* is right, not only the screen.

| # | Scenario | Pass when |
|---|---|---|
| A1 | **1 place, field hidden** (Москва) | No city/region row; address required; order stores city Москва in billing (+ shipping where it exists); address suggestions only inside Москва |
| A2 | 1 place, hide flag **off** | City row visible, prefilled, still limited to Москва (a one-item list) |
| A3 | **3 places** (Москва, Химки, пос. Коммунарка) | Select with exactly three options; a fourth cannot be chosen; address locked until a place is picked; suggestions scoped to it |
| A4 | 30 places (> 25) | Searchable list; only listed places found; the network panel shows **no** DaData suggest call for the settlement level |
| A5 | **Returning customer**, saved city Тула, profile + session, 3 places | Field empty with the rejected-city line; Tula street cleared; order cannot be placed until a place is picked |
| A6 | Returning customer, saved city Тула, 1 place hidden | Order carries Москва; no Tula street prefilled |
| A7 | Returning customer, saved city **inside** the list | Everything prefilled, no line |
| A8 | **Forged submit**: classic form with `billing_city=Тула` and no cookies / stale JS; Store API checkout POST with a Tula address | Rejected with the sentence of §4.4 (400 on the Store API); nothing created |
| A9 | **billing_only** shop (`woocommerce_ship_to_destination = billing_only`) | Only billing set; A1/A3 behave the same; order OK |
| A10 | Both sets (ship to a different address) | Both get the same widget; validation follows the set that decides delivery; order OK |
| A11 | **Other carrier active** (flat rate, СДЭК) | Their buyers get only the list; nothing offered outside; СДЭК points belong to the chosen place (case 5 of §8) |
| A12 | Range method: cart (no street) and checkout (house-level address) | Cart: approximate price + notice; checkout: exact price; outside the list never reached |
| A13 | **Licence not in force** | Restriction off (buyer can choose any city), range method hidden, notice on the tab; renewing restores it |
| A14 | Switch the mode **off** after use | Widget returns to the shop's own «Локация» settings, no leftover state |
| A15 | Active location provider is **not DaData** | The list is offered from re-resolved records; a place that does not resolve is dropped and flagged on the tab |
| A16 | Save with mode on and an empty list; with a duplicate place | Blocking errors of §3.2; nothing saved |
| A17 | Settings page without a DaData key | Restriction off + notice (§3.5), tab still editable |

## 12. Provisional register

| # | Gap | My choice | § |
|---|---|---|---|
| PC1 | Layer for a store-wide restriction | Generic framework **Allowed_Localities** with one filter; the plugin is the first source; names are provisional | §2 |
| PC2 | List stored as DaData records but the active provider differs | Provider-agnostic `permits()`; options rendered from records re-resolved through the active provider; unresolved places dropped and flagged | §2, §3.5 |
| PC3 | Two sources of a restriction | First wins, second `_doing_it_wrong()` | §2 |
| PC4 | "No maximum" vs payload size | No cap; ≤ 25 places: preloaded `<select>`; more: searchable list answered locally | §4.2 |
| PC5 | The hide toggle "only when exactly one city" | Always visible when the mode is on; server applies it only for exactly one place; default **off**; no new ADR-008 operator | §3.1 |
| PC6 | How a field is "hidden" | Present and posting, hidden by the framework's visibility mechanism (classic) / locale `hidden` (block); never `unset()` | §4.1 |
| PC7 | Region / state | Hidden in both modes, derived from the place; untouched where WooCommerce has no states | §4.1, §4.2 |
| PC8 | Postcode | Untouched | §4.1 |
| PC9 | My Account address form, order-edit screen, REST/API orders | Not restricted | §1, §8 |
| PC10 | Payer in another city (billing restricted too) | Accepted cost; alternative needs render-time active-section knowledge | §4.3 |
| PC11 | Multi-country list | Buyer sees the places of the chosen country; none → «В выбранную страну доставка не осуществляется» | §4.4 |
| PC12 | Saved street of a rejected city | Cleared | §4.5 |
| PC13 | Duplicate availability check in the range method | Not built | §6 |
| PC14 | Restriction without a licence | **OFF** (trade-off in §7) | §7 |
| PC15 | Pickup points outside the list | Scoped by the chosen place; UNVERIFIED per carrier on the rig; add a `permits()` check in the pickup handler if needed | §8 |
| PC16 | Method `City_Limit` entirely outside the store list | ANDed, no warning | §8 |
| PC17 | `woocommerce_package_rates` stripping for non-Woodev carriers | Not built; submit validation is the guarantee | §8 |
| PC18 | Framework version carrying this | The first release after 2.0.26 | §10 |
| PC19 | Settlement-level coverage of DaData for Химки / пос. Коммунарка / Зеленоград | UNVERIFIED; recorded fixtures in C14 settle it | §10 |

**UNVERIFIED summary:** the block address form's handling of a `hidden` field's value (F5 probe); DaData settlement-level answers for the
three example places (C14 fixtures); pickup-point cities per carrier (rig A11); whether WooCommerce's classic form renders a
`type=hidden` city row (not relied on — the design uses CSS/JS visibility); the framework resolver always choosing the highest copy
for every installed plugin (READ in the resolver, not exercised here).

**Needs the operator:** nothing blocks the work. Two choices worth a glance: **PC14** (restriction off without a licence) and **PC10**
(billing city restricted as well as shipping).

## Related

- [2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) — the v2 spec this extends; D1–D16, §2.3 data flow, §6 checkout, §2.6 licence gate.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the customer chain, staleness gate and default locality read in §2 and §4.5.
- [2026-10-04-sp11-block-checkout-design.md](2026-10-04-sp11-block-checkout-design.md) — block checkout adapter, `RouteException` pattern used in §4.4.
- [2026-08-12-location-provider-design.md](2026-08-12-location-provider-design.md) — provider capabilities and the DaData `locations` filter behind §4.1.
- [../adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — why the hide toggle cannot depend on a row count (§3.1).
