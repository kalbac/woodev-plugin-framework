# «Доставка в пределах города» (range shipping 2.0.0) — design

**Date:** 2026-11-05. **Status:** draft r2 — revised after the Codex gpt-6.1-sol adversarial review of r1 (11 findings, all resolved
here, §13) and the operator's four decisions of 05.11.2026 (K6–K9, binding). Product gaps this spec still fills itself are
**Provisional** items `P1…P4` (§12); everything else is decided (operator) or an engineering decision with a proof obligation (§12.2).
**Target:** the same release `2.0.0` of «Расчёт стоимости доставки за расстояние» (plugin id `range_rate`) as
[2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) (called **v2 spec** below; its D1–D16 are binding and
are not reopened here). Two repos change: this framework (the store-wide restriction itself, **released in framework 2.0.26 — still
unreleased, draft release PR #1606**) and `kalbac/woocommerce-range-shipping` (the settings and the switch).

**Evidence labels:** **READ** (code or document read), **UNVERIFIED** (reason given — a rig or fixture step proves it),
**Provisional** (`Pn`). `fw:` = this repo's `woodev/`; `plugin:` = `kalbac/woocommerce-range-shipping` at `45df7aa`.

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
| K1 | The feature ships **in plugin 2.0.0**; its framework part ships **in framework 2.0.26 itself** (unreleased — no later framework release is needed) | operator 05.11.2026; correction of r1 |
| K2 | A list item is **any settlement** at DaData's city/settlement level (Химки, пос. Коммунарка, Зеленоград), not only a city | operator |
| K3 | The settings live on a **new sub-tab «Доставка в городе»**; sub-tabs become **Склады · Сервисы · Доставка в городе · Дополнительно** | operator |
| K4 | The option is a **plugin setting that acts store-wide**, for every shipping method | operator |
| K5 | Classic checkout is complete in the release; block-checkout gaps may follow it **only if classic is complete** | operator rule |
| K6 | **Only the DELIVERY address is restricted** — the address the order ships to; for a `billing_only` shop that is the billing address. A payer living in another city can order with «Доставить по другому адресу» (the payer's billing city is free). Replaces r1's PC10 | operator 05.11.2026 |
| K7 | **No licence in force → the restriction is OFF**, and the settings tab shows a clear notice (§7). Replaces r1's PC14 | operator 05.11.2026 |
| K8 | **DaData key removed or DaData down → the SAVED list keeps restricting checkout** (rendered from the stored records, validated server-side by the stored canonical identity); only address suggestions stop. Re-resolution has cache, negative cache and backoff (§2.4) | operator 05.11.2026 |
| K9 | Sub-tab name «Доставка в городе»; any settlement allowed; ships in 2.0.0 (framework part in 2.0.26) | operator 05.11.2026 |

## 1. Goal, scope, non-goals

**Goal.** A food-delivery shop (one city, or a city and its neighbours) switches one option on, lists its places, and from that moment
no buyer can place an order **delivered** outside them — on any shipping method, on the classic and the block checkout — while the
buyer's experience gets *simpler*: a hidden field (one place) or a short list (several).

| In scope | Non-goals (named once, not built) |
|---|---|
| Settings sub-tab, storage, validation, warnings (§3) | Radius / polygon zones, per-method place lists (the framework's per-method `City_Limit` stays as is) |
| A **framework** store-wide «allowed localities» restriction with a canonical identity (§2), checked server-side on every path that takes a delivery location (§4, §5) | Restricting the **My Account** address form, the order-edit screen, REST/API order creation (§4.7) |
| Classic + block checkout behaviour: hide / prefill / list / returning customer / billing-only shops (§4) | Postcode derivation from the place (DaData gives none at city level) |
| Licence and key behaviour (§7), range-method interaction (§6), coexistence with other Woodev carriers incl. pickup maps (§8) | A cap on the number of places (the operator: unlimited) — only a rendering rule, §4.2 |
| Restricting the payer's **billing** city | Never — K6 (it is free whenever a separate shipping set exists) |

## 2. Where the restriction lives, and what identifies a place

### 2.1 Layer: framework coordination, plugin-owned setting (engineering decision E1)

READ — every path that reads a customer location is **framework code shared by all Woodev carriers**. The **location registry and the
persisted customer data are shared**; but each plugin builds its **own** `Location_Service` facade (`Shipping_Plugin::get_location_service()`),
the block checkout runs the **first-registered** handler's integration, and several plugins' classic configs overlap on one page.
So the policy must be published once and read coherently from every facade and every repeated callback.

| Path | Where (READ) | Why a plugin cannot do it alone |
|---|---|---|
| The checkout widget config (`mode`, `popular`, `defaultLocality`, `regionFieldRemoved` …) | `fw: shipping-method/checkout/class-checkout-config.php` `build_location_block()` | The list/hide behaviour is a *client config*; a plugin would patch another module's output |
| Suggest / list / select REST routes, fleet-wide `woodev/v1/location/*` | `fw: shipping-method/rest-api/class-location-controller.php` `perform_suggest()`, `handle_select_request()` | One public route serves CDEK, Yandex, Post, Ozon and range alike |
| The customer's saved record, staleness gate, default-locality lazy trigger | `fw: shipping-method/location/class-location-service.php` `gate_chain()` (1117), `get_customer_record()` (386), `resolve_default()` (2164) | A record from before the switch-on must be *ignored* for every consumer |
| Server-side submit checks (classic `woocommerce_checkout_process`, Store API validation) | `fw: shipping-method/checkout/class-checkout-handler.php` `guard_custom_settlement()` (2305), `handle_store_api_validate_order()` (2538) | Every carrier plugin registers its own `Checkout_Handler`; the check must not depend on which is installed |
| Availability of a Woodev method | `fw: shipping-method/class-shipping-method.php` `City_Limit::permits()` call (~1699) | The per-method limit already runs here; the store-wide one is the same seam |

**Proposal.** A generic framework feature **«allowed localities»**; the range plugin is its only *source* today.

```
 range plugin (settings, licence) ──publishes──▶ filter woodev_location_allowed_localities
                                                        │
        Allowed_Localities_Registry::current(): ?Allowed_Localities     ← ONE request-scoped static, every facade reads it
                                                        │
      ┌───────────────┬──────────────────┬──────────────┴───────┬────────────────────────┬──────────────┐
  customer-record   REST suggest/       checkout config      submit + Store API         Shipping_Method   pickup
  gate + default    list/select         (list / hide)        normalization/validation   availability      selection gate
```

*Rejected — plugin-only:* the plugin would filter `woocommerce_checkout_fields`, `woocommerce_get_country_locale` and the REST responses
itself, fighting the framework widget that rewrites the same fields (the field-policy class runs at `PHP_INT_MAX - 10` so that nobody gets
the last word after it — `fw: class-checkout-field-policy.php:84`). *Rejected — a framework-owned setting:* the operator wants the option
in the plugin's own settings, and the framework must not grow a product switch only one plugin sells.

**One source, no arbitration (E3).** The filter is read once per request by the registry and memoised in a **static** (not per facade);
the callback is idempotent so repeated registration is harmless. First-wins arbitration and `_doing_it_wrong()` for hypothetical competing
products are not built: there is one source, and a merge of two restrictions has no defined meaning. Revisit only when a second source exists.

### 2.2 The value object (names are engineering, may change in review)

```php
namespace Woodev\Framework\Shipping\Location;

final class Allowed_Locality {            // one list item; immutable
    public function key(): string;            // record key, e.g. dadata:<fias_id> — THE identity
    public function provider_id(): string;
    public function country(): string;        // ISO alpha-2
    public function name(): string;           // OWN locality name, §2.3
    public function value(): string;          // the text posted as city (name without type), §2.3
    public function state(): ?string;         // WC state code derived once at save/load (Location_Service::wc_state_code_for_record), null if the country has no states
    public function label(): string;          // «г Пушкино, рп Черкизово» for the list
    public function record(): Location_Record; // the stored, scoping-complete record (§3.1)
}

final class Allowed_Localities {
    public static function from_stored( string $json, bool $hide_field ): self;     // framework codec, uncapped (NOT City_Limit::decode's 200)
    public function items(): array;                  // Allowed_Locality[]
    public function is_single(): bool;
    public function hides_settlement_field(): bool;  // is_single() && the flag
    public function resolve( Destination $d, ?Location_Record $chain_settlement ): ?Allowed_Locality; // §2.4 — the ONE decision function
    public function item_for_key( string $provider_id, string $key ): ?Allowed_Locality;
}
Allowed_Localities_Registry::current(): ?Allowed_Localities   // null = no restriction
apply_filters( 'woodev_location_allowed_localities', ?Allowed_Localities $current ): ?Allowed_Localities
```

`Destination` = `{ country, state, city }` of the delivery address being judged (customer, package, order — §4.4).

### 2.3 Locality granularity — city versus nested settlement (finding 3, K2)

READ: `Dadata_Provider::record_from_dadata_fields()` (`fw: …/providers/class-dadata-provider.php:1396-1430`) **prefers `city` as the record's
`settlement`** and keeps the real settlement inside it in `nested_settlement`. A pick of «рп Черкизово» (city Пушкино) therefore has
`key` = Черкизово's FIAS id, `settlement.name` = «Пушкино», `nested_settlement.name` = «Черкизово». Classic `settlement_record_value()`
(`checkout-handler.php:2422`) and the block `recordCity()` read `settlement`, i.e. they would post «Пушкино». The contract for the allowed list:

| Rule | Definition |
|---|---|
| **Own locality** | `Location_Record::own_locality()` (new, pure): `nested_settlement` when present, else `settlement`. An item's `name()`/`value()` come from it; its identity is `record.key()` (the finest component's id) |
| **An item allows exactly itself** | A city item «Пушкино» does **not** allow the sibling settlement «Черкизово»; Черкизово must be listed itself. Two siblings under one city are two distinct items with distinct keys, names and values (**Provisional P3**) |
| **Posted / displayed value** | The widget shows and posts `Allowed_Locality::value()` — shipped per item in the config (`allowed[].value`), so neither client derives it. Clients do **not** call `recordCity()` / `fieldValueFor()` for an allowed item |
| **Carriers keep the old mapping** | The record mapper, `settlement_record_value()` and the address-record parent-city behaviour stay exactly as they are for carrier address records and for the unrestricted case — only allowed-list paths use `own_locality()` |
| **Duplicates** | Two rows are duplicates when their keys are equal, **or** country + normalised own name + state code are all equal |

### 2.4 Canonical identity and matching — NO name fallback (findings 1, 8)

r1's text/name rules are replaced. The decision function is `Allowed_Localities::resolve( Destination, ?chain_settlement ): ?Allowed_Locality`; it is
pure and works from **stored data only** (no provider call, no network), which is what lets K8 hold when DaData is gone.

1. **Country** must equal the item's country (compared separately, never inferred from a record the client sent).
2. **Chain record present** (the server-held customer record — it entered through `/select`, which canonicalises it, §4.6):
   - same provider as the item: its `key` must **equal** the item's key. A different key is rejected **even when the name is equal** —
     no name fallback after a key mismatch;
   - another provider (the shop's active provider is not DaData): accepted only if its key equals the **mapped counterpart** of an item
     (the re-resolution below); otherwise it is judged as a text submission (next point), never by name alone.
3. **No chain record / text submission** (cached page, stale JS, hand-built form): accepted only when **country + normalised own name
   and state code all equal exactly one item**. A missing posted state is **rejected** (not guessed) — `Благовещенск` without a region cannot
   mean Амурская область. Where the country has no WooCommerce states the text path is rejected and the buyer is asked to pick from the list.
   Never `Location_Record::is_same_locality_as()` (it tolerates a missing region — a kinship proof, not a boundary) and never
   `matching_candidates()` (re-resolution only).
4. **A `/select` for the settlement level never trusts the posted record JSON** (`Location_Record::from_array()` validates structure,
   not provenance): the server accepts only a `(provider, key)` that names an item or its mapped counterpart, and **stores the server-side
   canonical record**, discarding the posted components and coordinates (§4.6).
5. Deeper levels (street, house) keep today's trust model — they never decide allow/deny; the settlement and the country do — but must
   satisfy `is_within( item.key )` on the same provider.

**Mapped counterpart (only when the active provider is not DaData).** `reresolve_stranded_record( $item_record, true )` returns a record of the
active provider; `matching_candidates()` there already demands the stored region and a **single** candidate (READ, `class-location-service.php:2400`).
Result cache `woodev_allowed_map_<hash(list)>_<provider>` (option, non-autoload): **success** 7 days; **no/ambiguous match** (negative) 1 h;
**provider error or timeout** 5 min, doubling per repeat to a 1 h cap (reset by one success). A `Call_Guard` id `allowed_reresolve` caps
automatic calls at 200/day and **3 resolutions per request**, never inside a checkout request without `Woodev_API_Request_Purpose::run_at_checkout`'s
budget; it runs only while the restriction is in force (so never without a licence — the v2 spec §2.5/§2.6 outbound-call rule) and **never when
DaData is the active provider** (the stored records are native). An unmapped item is not lost: it is rendered from its stored record and judged
by the text path (point 3). **Removed key, provider switch, outage, all-unresolved → the restriction stays and the list still renders** (§7 matrix).

## 3. Settings (plugin)

### 3.1 Fields and storage (finding 2, finding 11)

New sub-tab **«Доставка в городе»** (section id `city_mode`) between «Сервисы» and «Дополнительно»: one more `Settings_Section` in
`Range_Plugin::get_tab_settings_providers()` (`plugin: includes/class-range-plugin.php`), a handler
`Woodev\Range_Shipping\City_Mode\City_Mode_Settings extends \Woodev_Abstract_Settings` modelled on `Warehouse_Settings`
(READ, `plugin: includes/warehouses/class-warehouse-settings.php`). Options (namespace `range_rate`, so `woodev_range_rate_*`):

| Setting | Type / control | Option | Autoload | Notes |
|---|---|---|---|---|
| `city_mode` | boolean, toggle | `woodev_range_rate_city_mode` | yes | the master switch, default **off** |
| `city_mode_cities` | string, `records-table` | `woodev_range_rate_city_mode_cities` | no | one column «Населённый пункт» = `location-picker`, `level` `settlement`, `provider` DaData (like the warehouse address column, D3). Stored value = the control's JSON list of rows `{ "city": "<Location_Record JSON>" }` |
| `city_mode_hide_field` | boolean, toggle | `woodev_range_rate_city_mode_hide_field` | yes | «Скрывать поле «Населённый пункт»», default **off** (P1) |

**The stored record is NOT compacted with `City_Limit::encode()`.** That codec nulls `raw`, but `Dadata_Provider::native_parent_ids()`
(`class-dadata-provider.php:1188`) reads `region_fias_id / area_fias_id / city_fias_id / settlement_fias_id` **only from `raw`** — a round-tripped
«г Пушкино, рп Черкизово» turned the address `locations` filter from `settlement_fias_id` into `city=Пушкино` (measured by the critic). The
framework codec `Allowed_Localities::from_stored()` / `encode()` therefore keeps `raw` **reduced to the scoping allowlist**
`fias_id, fias_level, region_fias_id, area_fias_id, city_fias_id, settlement_fias_id` (≈ 6 short strings per row — everything else in
`raw` is dropped), and the save validator **refuses a DaData row without `fias_id`**. No new control, no provider change: the allowlist
makes `native_parent_ids()` return what it returned for the full record (a unit test round-trips save → reload → address `locations`
for «Черкизово» and asserts equality with the original). For a non-DaData record nothing is kept. The codec is uncapped (no 200-record cap).

Reuse ladder: the `records-table` and `location-picker` controls exist (F1, framework 2.0.26 — READ `fw: settings-api/class-control.php:118,128`);
`Records_Table::decode()` reads the rows. Autoload: the switch and the flag are read on every request that renders a checkout; the list is
read only when the switch is on (it can be large), so it is non-autoload.

**Conditions.** `city_mode_cities` and `city_mode_hide_field` carry
`show_if = [ 'setting' => 'city_mode', 'operator' => '=', 'value' => true ]` — a **boolean** `true`, as ADR-008 requires for a toggle
(`Woodev_Setting::evaluate_conditions()` stringifies `true` to `'1'`; the r1 string `"yes"` evaluated false, hid both fields and stripped
them from the save payload by `filter_visible_values()`). No new grammar or operator.

**The hide toggle cannot be shown "only when exactly one city"**: ADR-008 has no row-count operator and its controllers are scalar
(READ `adr/008-conditional-fields-operator-set.md:28`). So it is visible whenever the mode is on, its text says «Работает, когда в списке
один город», and the server applies it only when the list has exactly one place. **Provisional P1** (alternative: a `count` operator —
rejected as a framework change for one toggle).

### 3.2 Validation (server, on Save)

| Rule | Message (Russian, for a novice) |
|---|---|
| Mode on and the list empty | «Добавьте хотя бы один населённый пункт — иначе ни один покупатель не сможет оформить заказ.» |
| A row is not a DaData settlement-level record with a name, a country and a `fias_id` | «Выберите населённый пункт из подсказок.» (row prefix «Строка N:» is the table's own) |
| The same place twice (§2.3 duplicates) | «Этот населённый пункт уже в списке.» |
| Mode **off** | the list is *kept* and not validated — a merchant who switches off and on again does not retype it |

The browser is not trusted: the checks run in `validate_row()` / `validate_values()` like `Warehouse_Settings`, and the record is re-read
with `Location_Record::from_array()` (an invalid record throws and is refused). **No upper bound** is enforced (operator). A runtime
reading of "mode on, list empty" (only a crafted write can produce it) is **P2** (§7).

### 3.3 The page text

```
 Доставка в городе ─────────────────────────────────────────────────────────────
 [x] Доставка в пределах города
     Включите, если вы доставляете заказы только в своём городе или ещё в нескольких
     ближайших — например, доставка еды или цветов. Покупатель сможет выбрать на
     оформлении заказа только населённые пункты из вашего списка. Это действует на
     весь магазин сразу — для всех способов доставки, а не для одного.
     Ограничивается адрес, куда доставляется заказ. Плательщик может жить в другом
     городе — для этого ему достаточно выбрать «Доставить по другому адресу».

   ┌─ Важно ──────────────────────────────────────────────────────────────────┐
   │ Если в магазине есть другие службы доставки — например, СДЭК или Почта   │
   │ России, — их покупатели тоже не смогут выбрать город вне вашего списка.  │
   │ Включайте режим, только если вы действительно не отправляете заказы      │
   │ дальше.                                                                  │
   └──────────────────────────────────────────────────────────────────────────┘

   Населённые пункты
   Покупатель сможет выбрать только их. Выбирайте из подсказок — подойдёт город,
   посёлок или район, например Химки, пос. Коммунарка, Зеленоград. Если нужны и город,
   и посёлок внутри него, добавьте их отдельно.
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

Empty table text: «Добавьте хотя бы один населённый пункт — город, посёлок или район города.» The «Важно» box is the operator's requirement that
the merchant is told **explicitly**; it is shown whenever the switch is on (a framework info block, not a tooltip). Merchant-facing strings are Russian
msgids in the plugin (admin = Russian is allowed, `DOCS-SCHEMA` i18n rule); buyer strings added to the **framework** are English msgids with a
Russian catalogue entry (storefront rule #567). Both listed in §5.

### 3.4 What the code reads, and when it publishes

`City_Mode\City_Mode` (plugin) hooks `woodev_location_allowed_localities` **only when the switch is on, the list is non-empty and
`License_Gate::is_in_force( Range_Plugin )`** (§7). It builds `Allowed_Localities::from_stored( $json, $hide_flag )`. Nothing else in the plugin
touches the checkout; the framework does the rest. The framework option layer is never written (the plugin does not change the shop's shared
location settings).

### 3.5 Live notices on the settings page (computed on render, never stored)

Computed like `apply_default_locality_status_note()` (READ `fw: location-provider-registry`):

| Condition | Notice (novice-clear) |
|---|---|
| Licence not in force (K7) | **«Ограничение городов отключено: лицензия не действует. Сейчас покупатели могут оформить заказ в любой город, в том числе по другим способам доставки. Введите или продлите лицензионный ключ — ограничение включится само.»** |
| DaData key missing or DaData unreachable (K8) | «Подсказки адресов сейчас не работают: не указан ключ DaData или сервис недоступен. Ограничение по городам продолжает действовать по вашему списку; покупатель выберет город из списка, но подсказок при вводе адреса не увидит.» |
| An item the active provider cannot map to exactly one place (only when the active provider is not DaData) | «Населённый пункт «X» не найден службой подсказок, которую использует магазин, — он остаётся в списке и разрешён, но подсказки адреса для него могут не работать. Выберите его заново.» |
| The shop's «Локация» default locality (policy `fixed`) lies outside the list | «Город по умолчанию в настройках «Локация» не входит в этот список и не используется» |
| Mode on, list empty (crafted write, P2) | «Список пуст — ограничение выключено. Добавьте хотя бы один населённый пункт.» |

## 4. Checkout

The server is the authority on every row below; the client only presents. **Delivery section** (K6) = the address the order ships to:
`Address_Target` (READ, `fw: pickup/class-address-target.php`) — billing when `wc_ship_to_billing_address_only()` or when «Доставить по другому
адресу» is **off**; shipping when it is **on**. The other (payer) section is never restricted, never hidden, never forced.

### 4.1 One place, field hidden (`is_single() && hide flag`)

| Aspect | Behaviour |
|---|---|
| The customer's record | The allowed place is written to the delivery chain through `Customer_Location_Store::set( $canonical, false )` — **explicit** (not implicit), the server-side canonical record (§2.4 point 4) — **not** through `resolve_default()`: `Location_Service::get_customer_record()` (386) returns early at `customer_saved_city()` (1690) / `resolve_saved_city_entry()` (1844) before it ever reaches `resolve_default()`, and a surviving region record would block it too. So the hidden-single path first **drops every record of the chain and the saved city** that does not belong to the item, then sets the canonical record |
| Settlement field of the delivery section | Not shown; value = `Allowed_Locality::value()`; **it still posts** on classic. Classic: hidden by the framework's visibility mechanism (JS + CSS class, as `address_field=hide_for_pickup` already is, READ `checkout-field-policy.php` header), **not** `unset()` — an unset field's value never reaches the order and `restore_invariants()` (856) would put it back. Block: see §4.3 |
| Region / state | Not shown either; value = `Allowed_Locality::state()` (where the country has WC states), written explicitly — **not** through the `regionFieldRemoved` short circuit (§4.3). Where WooCommerce has no states for the country (RU) nothing is invented; the free text is kept |
| Postcode | **Untouched**: shown or removed per the shop's own «Поля» setting, required per WooCommerce's locale. DaData gives none at city level. **A postcode that belonged to a rejected old address is discarded before the replacement city is prefilled** (§4.5) |
| Address (`*_address_1`) | **Stays visible and required — also where a pickup policy would relax it** (§4.2a). DaData address suggestions are scoped to the item: `perform_suggest()` already turns the chain's settlement record into `Location_Scope::within( $record, 'address' )`, which the DaData provider sends as an exact `locations` filter built from the record's FIAS ids (READ `class-dadata-provider.php:1002-1018,1106,1188`). That works **because §3.1 kept the scoping ids in the stored record** — forcing a compacted record would have degraded it to `city=<name>` |
| Country | Untouched; must equal the item's country (§2.4 point 1) |
| Payer section | Untouched, free (K6) |

### 4.2 Several places

| Aspect | Behaviour |
|---|---|
| Settlement field of the delivery section | A choice of **only the listed places**, placeholder «Выберите город» (msgid "Choose your city"). Up to `ALLOWED_SELECT_MAX = 25` places (a tunable constant, not a requirement): a plain `<select>` with every option preloaded in the config (key `allowed`, wire shape `{ key, label, level, value, state, record }`). More: the stock searchable list, but its `/suggest` answers come **locally from the list** (substring over the labels, no provider call), and the config carries **only `allowedCount`, not the list** — so there is no cap and no unbounded payload |
| Field mode | Forced at config-build time to the list/searchable mode — **without writing** the store setting. `typeahead` (free text, never validated against a provider — READ `guard_custom_settlement()` docblock) cannot enforce anything, and `allow_custom_settlement` is ignored while the restriction is on |
| Region | Hidden, derived from the pick (`state`) — the places may span regions (Москва + Химки), and a region picker would contradict the list |
| Address | Locked until a place is chosen (existing cascade: the address level waits for its settlement parent — READ), then suggestions scoped to the chosen item as above |
| Nothing chosen yet | No delivery destination → no rate beyond what the v2 spec §6.1 gives (a cart-page quote needs a destination) |

### 4.2a «Адрес» stays required over the pickup policy (finding 6)

READ: `Checkout_Field_Policy::checkout_fields_contribution()` (557-581) sets `address_1.required = false` when `address_field = hide_for_pickup` and a pickup
method is chosen (`hidden_for_pickup()`, 607), and `checkout-field-classic.js` hides the row; the block side reads the same effective settings.
r1 changed only city/state. The precedence is now explicit:

- **One override at the single source:** `Checkout_Field_Policy::effective()` (314) returns `address_field = 'show'` while
  `Allowed_Localities_Registry::current()` is non-null. Every consumer of `effective()` (classic `checkout_fields_contribution`, the JS config
  `Checkout_Config`, the block locale contribution, the admin order wizard's `address_rules_for()`) therefore agrees; `postcode_field` is untouched.
- **A server check independent of the policy:** the delivery-section guard rejects an empty `*_address_1` («Укажите адрес доставки») in classic and
  in the Store API validation, so a crafted submit cannot rely on the client or on a relaxed `required`.
- Pickup-point address replacement still fills `address_1`/`postcode` after selection (§8 case 5); the field is simply never empty-and-optional.

### 4.3 The actual clients — what exists and what F4/F5 build (finding 4)

READ: the classic cascade builds **one active chain** (`location-cascade.js::buildChain()`, ~978), billing **or** shipping — not two simultaneous widgets. The block
`LocalityChooser` is a **separate control beside** the native City/State fields; `register.tsx` registers a billing chooser only for
`billingOnly && forcedBillingAddress`; `mapping.ts::resolveNativeAddress()` deliberately does **not** fill state when `regionFieldRemoved = true`. With K6 the
design follows the existing single active chain and constrains **only the delivery section**:

| Surface | Delivery section presentation |
|---|---|
| Classic, one set / `billing_only` | The cascade attaches to billing (as today, `location_target_sections()` 4600); it is the delivery section: list / hidden city+state |
| Classic, separate shipping set | The active chain target follows the «Доставить по другому адресу» toggle (the client already tracks it, `buildChain()`): toggle **on** → the **shipping** set is constrained/hidden and billing is plain; toggle **off** → billing is delivery and constrained, shipping is not shown. Hiding is re-evaluated on every toggle; the server judges `Address_Target`, never the toggle's value alone |
| Block, normal | The chooser belongs to the **shipping** address form (delivery); billing (payer, when «use same address» is off) is plain WooCommerce |
| Block, `billing_only` | The chooser belongs to the billing form (`billingOnly && forcedBillingAddress` — exists) |
| Native Block City / State beside the chooser | In city mode the native **City** field of the delivery form is **locked** (read-only, value = `allowed[].value`) and **State** is written from `allowed[].state`; `resolveNativeAddress()` gains a **city-mode branch that fills state from the item, bypassing the `regionFieldRemoved` short circuit** (the guard stays for every non-city-mode config). Hidden-single: the chooser is not mounted and `city`/`state` use the locale `hidden`; the client **explicitly writes** the canonical city/state/country to the cart address store — the design never relies on a hidden field posting |
| Address-store hydration | A returning customer's store (WooCommerce Blocks hydrates `cartStore` from the session customer) is cleared **in the address store itself** (`city`, `address_1`, `address_2`, `postcode` of the delivery address) when the saved city is rejected (§4.5); `woocommerce_checkout_get_value` cannot do this on Blocks |

F4 owns the classic rows, F5 the block rows; these rows are acceptance cases (§11), not config-flag assumptions. If product ever wants the payer's
separate billing fields constrained too, that is a new decision with its own client budget (K6 closes it for now).

### 4.4 Server enforcement and normalisation (finding 5, finding 1)

Three destinations are judged — the **customer** (what shipping/tax calculate from), each **package** (`$package['destination']`), the **order** (what is stored) — and
they must **agree** on the resolved item. Normalisation happens **before** totals; validation happens **again** before payment.

| Path | When | What |
|---|---|---|
| Store API **cart** update (`woocommerce_store_api_cart_update_customer_from_request`, READ `CartUpdateCustomer.php:212`) | before the cart recalculates rates | `Allowed_Localities::resolve()` on the delivery address of `$customer`. Hidden single: **overwrite** city/state/country with the item (billing too when `billing_only`). List mode: resolved → write the canonical city/state; not resolved → leave the customer as is and mark the request «outside» (rates of Woodev methods are then unavailable by the shared gate, §5) |
| Store API **checkout POST** (`woocommerce_store_api_checkout_update_customer_from_request`, READ `Routes/V1/Checkout.php:954`, **before** `calculate_totals()` at `process_order`) | before totals and before `validate_order_totals()` | the same normalisation on the posted address. A hidden single city posted blank or stale (Tula) is overwritten **here**, so range availability, other carriers and tax already see the item — forcing only the finished order would be too late (READ: `create_or_update_draft_order` / `update_order_from_request` run after totals) |
| Store API **draft** (`woocommerce_store_api_checkout_update_draft`, PUT/PATCH) | while the block saves the form | the same normalisation (draft only; no error) |
| Block, validate-before-payment (`handle_store_api_validate_order()`) and the `…update_order_from_request` fallback for older WC | on the finished order | Re-resolve the **order's** delivery address; require it equals the customer's and the shipping line's package destination (same item); `address_1` non-empty (§4.2a). Failure → `RouteException` 400 with the sentence below; a disagreement uses «Адрес доставки изменился. Проверьте его и повторите.» (msgid "The delivery address changed. Please check it and try again.") |
| Classic `woocommerce_checkout_process` (READ: runs before WooCommerce's final `get_posted_data()`, so no earlier hook is needed) | the framework's `guard_custom_settlement()` slot | Hidden single: **overwrite** posted city/state/country of the delivery section (and billing in `billing_only`) with the item. Otherwise `resolve()` on the delivery section with its chain record and the posted text; a miss → `add_error()` |
| Classic cart calculator (`handle_calculated_shipping`, 1572) and `woocommerce_package_rates` | before rates | the same `resolve()` on the address being quoted; hidden single forces it; a miss → no destination → no rates of Woodev methods |

Sentence on failure: «Мы доставляем только в: Москва, Химки. Выберите населённый пункт из списка.» (msgid "We deliver only to: %s. Please choose your city from the list.").
**Explicit rejection in list/visible mode; canonical forcing in hidden mode** — the two are separate behaviours with separate acceptance rows (A8a / A8b). A multi-country list
shows the buyer the items of the chosen country (E-decision, §12.2); none → «В выбранную страну доставка не осуществляется». A different country is never silently forced while
pricing another one.

### 4.5 Returning customer with a saved other city

| Saved state | Result |
|---|---|
| Chain record (session / user option) outside the list | Treated as **absent** like a stale record (`is_customer_record_stale()` rule c, new): `gate_chain()` drops it and every deeper level. Only the **session / checkout** view is affected; it never edits the customer's stored My Account address |
| WooCommerce's own saved city text (profile / session customer), no record — `customer_saved_city()` / `resolve_saved_city_entry()` (#1075) | Used only if it resolves (§2.4 point 3, with the saved state) to an allowed item; otherwise ignored. **Several places:** field empty with the placeholder and one line: «Прежний город «Тула» не входит в зону доставки — выберите город из списка.» (msgid "The saved city “%s” is outside our delivery area. Please choose a city from the list.") **Hidden single place:** replaced by the item, after the chain/saved-city cleanup of §4.1 |
| Saved street / postcode of the rejected city | **Cleared in the checkout view**: classic prefill returns `''` for `address_1`, `address_2`, `postcode` through `handle_checkout_get_value()`; block: cleared in the cart address store (§4.3). The customer's stored profile address is untouched |
| A previously saved address in My Account | Untouched (§4.7) |

### 4.6 REST routes (shared with account and admin pickers)

The routes serve other callers (My Account, the admin default-locality picker, order wizard). The client therefore sends `context` (`checkout` | `cart` | `account`) copied from its
localised config. **Reads (`/suggest`, `/list`) apply the restriction only when `context ∈ {checkout, cart}`; an absent or `account` context is unrestricted** — current behaviour, so
nothing outside checkout changes. This is presentation only and not a security boundary (spoofing `account` merely yields unrestricted suggestions); **submit enforcement (§4.4) never depends
on it**. `/select` with `context ∈ {checkout, cart}` follows §2.4 point 4: unknown `(provider, key)` → 422 `woodev_location_outside_allowed`, chain untouched; otherwise the canonical record is stored.
`level=address` without `within`: exactly one item → that item as parent; several → empty.

### 4.7 My Account, admin, API (finding 9)

`Checkout_Handler::enqueue_account_assets()` (1317) builds the same `Checkout_Config` and serves the same `location-cascade.js`; `account_location_block()` (1442) only clears a few keys. Defined
presentation: **the account form is unrestricted and shows the customer's own saved text** (as today). Implementation: the account path builds the config with the restriction **suspended**
(`Checkout_Config` gets a `restriction = false` input; `allowed`, `hideSettlement`, forced mode, forced `allowCustomSettlement` are simply not emitted — not cleared afterwards), the config
`context` is `account`, and the service's saved-record reads for that page run with the gate suspended (rule c off). An address saved there is still judged at checkout. Switching the mode
off leaves no stale flags because nothing is persisted (§4.8). Order-edit screen and REST/API order creation are unrestricted — only the checkout destination is enforced.

### 4.8 Switching the mode off

Nothing special: the gate stops dropping records; the chain, if still present, is valid again; no list/hide flag was persisted anywhere (config is computed per request).

## 5. Framework changes (the work behind §2–§4)

Files are existing ones unless marked new. All ship in **framework 2.0.26**.

| Unit | What |
|---|---|
| `woodev/shipping-method/location/class-allowed-locality.php`, `class-allowed-localities.php`, `class-allowed-localities-registry.php`, `class-allowed-locality-mapping.php` (new) | The value objects, codec (scoping-`raw` allowlist), `resolve()`, request-scoped registry, mapping cache with negative cache/backoff (§2.4). Pure and unit-tested |
| `class-location-record.php` | `own_locality()` (§2.3); the mapper in `providers/class-dadata-provider.php` is **not** changed |
| `class-location-service.php` | `is_customer_record_stale()` rule c; hidden-single cleanup + explicit write; `customer_saved_city()` / `resolve_saved_city_entry()` guards; `resolve_default()` untouched; the gate works while `is_active()` is false (K8) |
| `rest-api/class-location-controller.php` | `context` param; `/suggest` & `/list` from the list; `/select` canonicalisation + 422; address-parent rule |
| `checkout/class-checkout-config.php` | `build_location_block()` gains `allowed` / `allowedCount`, `hideSettlement`, forced `mode.settlement`, `allowCustomSettlement=false`, `restriction` input (§4.7), `context`, and the buyer strings through `location_i18n_strings()` / the `woodev_location_i18n` filter (READ, 845) |
| `checkout/class-checkout-handler.php` | `guard_allowed_localities()` beside `guard_custom_settlement()`; the three Store API normalisation seams + final validation (§4.4); prefill rules (§4.5); account path (§4.7) |
| `checkout/class-checkout-field-policy.php` | `effective()` override for `address_field` (§4.2a); `locale_contribution()` / `checkout_fields_contribution()`: `city.hidden` / `state.hidden` while hidden; `restore_invariants()` must not re-insert a deliberately hidden-but-present field (hidden ≠ removed) |
| `class-shipping-method.php` (near 1699) | `is_available_for_package()` asks `Allowed_Localities::resolve()` on **each package's own destination** (+ the customer chain) and hides the method with reason `outside_allowed_localities`; ANDed with per-method `City_Limit`, whose permissive rules stay separate from this stricter boundary |
| `pickup/class-constraint-checker.php`, `pickup/class-pickup-selection-service.php`, `pickup/class-pickup-handler.php`, `rest-api/class-pickup-controller.php` | The pickup gate (§8 case 5) |
| `src/**` (checkout-blocks bundle, classic `location-cascade.js`) | §4.3 rows; strings + catalogue |

## 6. Interaction with the range method

- The method still prices by distance (v2 spec §2.3, unchanged). Outside-the-list addresses never reach `rate_package()` for a Woodev method: the
  shared package gate (§5) hides it. `rate_package()` still geocodes independently; **package/chain disagreement is a covered test case** (F2), which is why no second plugin-only
  gate is built (E13).
- `Destination_Point_Resolver` is untouched. With a hidden place the explicit chain record is a DaData settlement record (now carrying its FIAS ids), so the **cart-page quote** (no street
  yet) takes the v2 spec §2.3 step (b): the settlement point, precision `approximate`, and the §6.2 notice «Стоимость указана примерная…» shows until the buyer types the address.
- **Active provider ≠ DaData:** the chain record is the mapped counterpart's; the resolver already re-geocodes through DaData (v2 spec §2.3 «DaData ownership»). Nothing new.
- `Range_Checkout_Handler` keeps declaring the three `source_location()` fields; the restriction is not a field of the plugin.

## 7. Licence, key and effective-policy matrix (K7, K8, finding 8)

Standing rules: the licence gate is the default, no invented safeguards, no automatic outbound call without a licence (v2 spec §2.6).

| State | Restriction | Checkout | Settings tab |
|---|---|---|---|
| Mode **off** | none | the shop's own «Локация» settings | — |
| **Licence not in force** (K7, decided) | **OFF** — `City_Mode` does not publish the filter | any city; the range method is hidden by the framework | notice §3.5 (first row); settings stay editable (the gate never covers settings pages, READ `class-license-gate.php`); renewal re-enables on the next request (`request_claim_refresh()`) |
| Licence in force, **DaData key removed / DaData down / layer inactive** (K8, decided) | **ON** from the stored list | the list is rendered from the stored records (`<select>` of `value`/`state`); **no address suggestions**; the chain is not written (no owner), submit judged by the text path (country + own name + state); previously saved chain records drop by the existing provider-ownership rule | notice §3.5 (second row) |
| **Active provider switched** to a carrier's own list | **ON** | rendered from the mapped counterparts (§2.4); unmapped items are rendered from stored records and judged by text | notice for unmapped items |
| **Transient provider error / all mappings unresolved** | **ON** | as the previous row; negative cache + backoff stop repeated calls | notice for unmapped items |
| Mode on, list **empty** (crafted write only) | **OFF** (P2) | any city | notice «Список пуст» |

**Trade-off of K7, recorded honestly.** With the licence lapsed the restriction is off, so a food shop's *other* carriers or a flat rate **can accept orders outside the configured
area** until the licence is renewed. This is the operator's decision: consistent with "no licence → no checkout offer" and with never acting on other vendors' methods after the licence
ends. The notice names the consequence plainly, which is why it says so in the first row of §3.5.

## 8. Coexistence with other Woodev plugins (CDEK, Yandex, Почта, Ozon, …)

They share the one registry and service, so the restriction applies with **no code in those plugins**. Conflict cases:

| # | Case | Resolution |
|---|---|---|
| 1 | The shop's «Локация» default locality (`fixed` / geoip) is another city | Ignored while the restriction is on (an out-of-list default is dropped by the same gate); the plugin's tab shows a note (§3.5) |
| 2 | Field mode `typeahead` (free text) | Forced to list/searchable at config-build time (§4.2); the store setting is not rewritten, so switching the mode off restores the merchant's choice |
| 3 | `allow_custom_settlement` on | Ignored while the restriction is on |
| 4 | Region field mode `related-list` / carrier-injected states (`inject_related_list_states()`) | Region hidden in city mode; states derived from the item |
| 5 | **Pickup-point methods — a mandatory gate, not an assumption** (finding 7) | READ: Ozon `Ozon_Point_Source::get_strategy()/fetch_points()` and Russian Post `Russian_Post_Widget_Point_Source` use **viewport** queries, and `Pickup_Controller::attach_location_context()` (`rest-api/class-pickup-controller.php:1253`) deliberately does **not** narrow viewport queries by a locality hint; `Pickup_Handler::store_api_replacement_address()` (1959) copies only street + postcode and **never the city**. So a point in Тула can be selected while the native city says Москва. **Gate:** the selected point's real locality (`Pickup_Point::get_locality()` + its region/state when the source carries one) is judged by the same `resolve()` text path (country + own name + state — a point without region discriminators is **rejected**, never guessed): (a) in the shared selection seam `Pickup_Selection_Service::confirm()` through its `$accept_point` callback and in `Constraint_Checker::check()` as a constraint with the existing non-selectable reason shown on the map; (b) before `store_api_replacement_address()` (returns `[]` when not allowed — its `$prepared['result']['allowed']` is the same verdict); (c) re-checked at submit in `pickup_point_errors()` for classic and Store API. Tests: Ozon/Post viewport pan to another city, a direct foreign point id, with the native city deliberately kept inside; CDEK/Yandex city-scoped lists as the control. Owned by card **F6** — not conditional on a rig surprise |
| 6 | The method has its own `City_Limit` (include / exclude) | ANDed with the store-wide list; its permissive/no-record rules stay separate from the stricter store-wide boundary. A method whose own list is entirely outside the store list is never available; no warning is built |
| 7 | Another plugin vendors an older framework copy | Irrelevant: the loader runs the highest copy (READ `class-framework-resolver.php`); `Range_Plugin` bundles ≥ **2.0.26** (§10), so the feature is always present when the plugin runs |
| 8 | The range plugin is deactivated | The filter is gone → no restriction; the options stay |
| 9 | Non-Woodev shipping methods (flat rate, free shipping, local pickup, a third-party carrier) | Not asked anything. **Order placement is guaranteed:** the checkout-level enforcement of §4.4 holds for them, because the destination they quote is the address that passed it. Their *pre-submit* remote rate calculations are **not** intercepted — a buyer with an outside address may see such a rate before submit and is then refused. `woocommerce_package_rates` stripping is **not** built. REST/API/admin order creation is not restricted |

## 9. Block-checkout gaps allowed after release (K5)

Release blockers on **both** checkouts: the server enforcement (§4.4 — all three normalisation seams, final validation, package gate, `address_1` required), the **pickup gate** (§8 case 5),
the REST canonicalisation (§4.6), the account separation (§4.7), the address-store clearing of a rejected address (§4.3 — a Tula street under a Moscow city is silent, so it is not a deferrable gap),
the licence behaviour, the settings page. Classic is **complete** only when additionally F4 is done: preloaded `<select>`, hidden rows tracking the delivery toggle, rejected-city line, billing-only shops,
returning customer.

If — and only if — F4 is complete, these block items may follow the release (each has a safe fallback because the server rejects everything outside the list):

| Gap | Fallback until it ships |
|---|---|
| Preloaded `<select>` in the block locality chooser | The stock searchable list with locally filtered suggestions (§4.2 > 25 behaviour) — fully restricted |
| Hiding the settlement/state fields in the block | Native City **visible, locked and prefilled** with `value`, State written from the item; canonical values forced server-side before totals (§4.4) |
| The rejected-city line | Silent: the saved value is dropped, the placeholder shows |

**UNVERIFIED (rig, card F5):** whether the block address form clears a `hidden` field's value on its own. The design does not depend on the answer — the client writes the canonical address
to the store and the server normalises before totals — but the fallback column is what to ship if hiding does not work.

## 10. Cards

`F` = framework repo (kalbac/woodev-plugin-framework), `C` = plugin repo. Numbers continue the v2 spec's (F1, C1–C13). Issues are created by the coordinator when the spec is approved.
**Release (K1):** F2–F6 are the work contained in **framework 2.0.26 itself** (unreleased, draft release PR #1606 — the coordinator owns that PR's contents); the plugin release commit
bundles the matching `woodev/` tag and sets the loader `framework_version` together (the existing rule). No later framework release is planned.

| Card | Scope | Depends | Size | Critic |
|---|---|---|---|---|
| **F2** | Identity core: `Allowed_Locality(ies)` + registry + codec (scoping-`raw` allowlist) + `resolve()` (§2.4) + `own_locality()` + mapping cache with negative cache/backoff/`Call_Guard` + `Location_Service` gates (rule c, hidden-single cleanup incl. saved-city early return, K8 works while inactive) + package-based `Shipping_Method` availability + `Checkout_Field_Policy::effective()` override. Pure unit tests incl. **homonyms, omitted region, conflicting key, forged record payload, cross-country, nested settlement with two siblings, save→reload→exact FIAS `locations`**, package/chain disagreement | — | L | **Codex, BEFORE merge** (it defines the boundary and the interface every later card consumes) |
| **F3** | Server enforcement: REST (`context`, local `suggest`/`list`, `select` canonicalisation + 422, address-parent rule), classic guard + forcing, the **three Store API normalisation seams** + final validation with customer/package/order agreement, cart calculator, billing-only, `address_1` guard, prefill rules (§4.5), account suspension (§4.7). Tests per path incl. a forged form with no chain record and a hidden-single request with blank/stale city | F2 | L | Codex, mandatory (security-shaped: a bypass is the failure) |
| **F6** | Pickup gate (§8 case 5): `Constraint_Checker` constraint + `Pickup_Selection_Service::confirm()` `$accept_point` + replacement-address guard + submit re-check, map non-selectable reason. Tests: viewport pan, direct foreign id, control city-scoped list | F2 (∥ F3) | M | Codex |
| **F4** | Classic client: `Checkout_Config` keys, select / searchable rendering, hidden rows following the delivery toggle, rejected-city line, strings + catalogue. UI Kit has nothing to add | F3 | M | rig check by the worker + Codex |
| **F5** | Block client: delivery-form chooser, native City lock + state write (city-mode branch of `resolveNativeAddress()`), address-store hydration/clearing, `city.hidden` / `state.hidden`, the UNVERIFIED hidden-value probe, fallbacks of §9 | F3 (F4 for the strings) | L | Codex; the §9 gaps may slip past release |
| **C14** | Plugin: `City_Mode\City_Mode_Settings` (sub-tab, three options, validation, texts of §3, **boolean `show_if`**), `City_Mode\City_Mode` (filter publication, licence gate), live notices (§3.5), recorded DaData fixtures for Химки / пос. Коммунарка / Зеленоград **and** the nested-settlement fixture (Черкизово), tests | F2 (interface only: develop against the §2.2 signatures) | M | Codex |
| **C15** | Plugin: range-method rig tests (cart quote with a hidden place, outside-list hide, package/chain agreement), docs (`docs/CURRENT-STATE.md`, product page text, materials package), Russian catalogue entries | C14, F4 | S | worker self + coordinator |
| **C16** | Rig acceptance (§11) classic + block, report with screenshots on disk | all | M | operator reads the report |

**Waves.** **W-A:** F2 ∥ C14 (disjoint repos; C14 codes against §2.2) — F2's critic runs before its merge. **W-B:** F3 ∥ F6. **W-C:** F4 ∥ F5 ∥ C15. **W-D:** C16. This runs inside the v2 spec's W5 slot: C13 (e2e,
materials) waits for C16, because the product text must describe this tab.

## 11. Acceptance on the rig

Run on the WordPress rig with DaData, the range method active, a second carrier active (a flat-rate zone and, when available, СДЭК, Ozon, Почта), WC current and current−1. **Each row on classic and on
block.** A row passes only when the *order* is right — inspect the final city/state/postcode/street, not the chooser label.

| # | Scenario | Pass when |
|---|---|---|
| A1 | **1 place, field hidden** (Москва) | No city/region row in the delivery section; address required; order stores Москва in the delivery address (+ billing in `billing_only`); suggestions only inside Москва |
| A2 | 1 place, hide flag **off** | City row visible, prefilled, locked to Москва |
| A3 | **3 places** (Москва, Химки, пос. Коммунарка) | Select with exactly three options; a fourth cannot be chosen; address locked until a place is picked; suggestions scoped to it |
| A4 | 30 places (> 25) | Searchable list; only listed places found; **no** DaData suggest call for the settlement level; the config carries `allowedCount`, not 30 records |
| A5 | **Returning customer**, saved city Тула, profile + session, 3 places | Field empty with the rejected-city line; Tula street/postcode cleared **in the checkout view** (classic prefill and block address store); order cannot be placed until a place is picked; My Account address unchanged |
| A6 | Returning customer, saved city Тула, 1 place hidden | Order carries Москва; no Tula street prefilled; **verify the forced settlement is really reached** — not blocked by a surviving region record or the saved-city early return in `get_customer_record()` — with (a) region + rejected settlement + house records, (b) only WooCommerce-native saved data |
| A7 | Returning customer, saved city **inside** the list | Everything prefilled, no line |
| A8a | **Forged submit, list/visible mode**: classic form with `billing_city=Тула`, no cookies / stale JS; Store API checkout POST with a Tula address | Rejected with the §4.4 sentence (400 on the Store API); nothing created |
| A8b | **Forged submit, hidden-single mode**: blank / stale / Tula city posted | Canonically forced to the item **before** totals; the order and the quoted rates carry Москва; no error |
| A8c | **Identity attacks** (list of Благовещенск, Амурская область) | Rejected: same name with **omitted region**; same-provider **different key**; foreign-provider same-name record with no region; **forged selected-record components / coordinates** on `/select`; **cross-country** posted country; Moscow versus Tula alone is not sufficient evidence |
| A8d | **Nested settlement** (Пушкино + Черкизово, two siblings listed) | Two distinct selectable items; posted value «Черкизово» (not «Пушкино»); city item alone does not allow Черкизово; **after saving and reloading the settings** the address `locations` filter is still the exact `settlement_fias_id` |
| A9 | **billing_only** shop (`woocommerce_ship_to_destination = billing_only`) | Only the billing set; A1/A3 behave the same; order OK; exercised with **both old native customer data and chain data** |
| A10 | Separate billing and shipping, **toggling «Доставить по другому адресу»** (classic) and the block's «use same address» | The constrained widget follows the delivery section on every toggle; the **payer's billing city outside the list is accepted**; native Block City is locked, State written; final fields consistent |
| A11 | **Other carrier active** (flat rate, СДЭК) | Order placement outside the list is impossible on every method; a third-party rate may be visible pre-submit for an outside address and is refused at submit (§8 case 9) |
| A11b | **Pickup points (Ozon / Почта / СДЭК)** with only Москва allowed | Panning the Ozon/Post map to Тула shows the points non-selectable; a **direct foreign point id** is refused before persistence and before address replacement; the native city deliberately kept inside |
| A12 | Range method: cart (no street) and checkout (house-level address) | Cart: approximate price + notice; checkout: exact price; outside the list never reached; package destination and chain agree |
| A13 | **Licence not in force**, then renewed | Restriction off (any city), range method hidden, the exact §3.5 notice shown; renewing restores it on the next request |
| A14 | Switch the mode **off** after use | Widget returns to the shop's own «Локация» settings, no leftover state, **no stale hide/list flags** |
| A15 | Active location provider is **not DaData**; provider switch | List offered from mapped counterparts; an unmapped item stays allowed and is rendered from its stored record; negative cache stops repeated calls |
| A16 | Save with mode on and an empty list; with a duplicate place; a row without `fias_id` | Blocking errors of §3.2; nothing saved. The two dependent fields **appear** when the switch is toggled on (boolean `show_if`) and are saved |
| A17 | **No / removed DaData key, DaData down, transient outage, all mappings unresolved** | The saved list **still restricts** (select rendered from stored records, text-path validation); no suggestions; notice shown; nothing locks the shop |
| A18 | **Address policy `hide_for_pickup`**, a pickup / local-pickup method chosen, **empty address** (and a crafted submit) | «Адрес» stays visible and required; rejected server-side on classic and Store API |
| A19 | **My Account** → Edit billing/shipping address with the mode on, then off | Unrestricted picker with the customer's saved text; REST calls carry `context=account`; no stale flags after switching off; a checkout still judges the saved address |
| A20 | **Hidden city omitted or stale in the Store API payload** (block) | Address normalisation precedes shipping/tax totals and `validate_order_totals()`; customer, session, package and order destinations agree |

## 12. Decision register

### 12.1 Provisional — genuinely open (my choice stated; none is decided)

| # | Gap | My choice | § |
|---|---|---|---|
| P1 | The hide toggle "only when exactly one city" — ADR-008 cannot count rows | Visible whenever the mode is on; its text says it works for one city; the server applies it only for exactly one place; default **off**; no new ADR-008 operator | §3.1 |
| P2 | Mode on but the list is empty (only a crafted write can do it) | Treated as **no restriction** + admin notice; the Save validator makes it unreachable from the UI | §3.2, §7 |
| P3 | Does a listed city also allow a settlement nested in it (Пушкино → Черкизово), or the reverse? | **No**: a listed item allows exactly itself; list siblings separately; address suggestions for a city item may still include its nested settlements' streets (DaData's city scope) | §2.3 |
| P4 | The cart page (shipping calculator) | Restricted exactly like checkout, because it quotes the delivery address; `context=cart` | §4.4, §4.6 |

### 12.2 Engineering decisions (critic-assessed; proof obligations, not operator questions)

`En` keeps the number of r1's `PCn` it replaces; the gaps (E5, E10, E14) are r1 items that became operator decisions (K6, K7) or Provisional P1.

| # | Decision | Where proven |
|---|---|---|
| E1 | Framework coordination layer, plugin-owned setting; shared registry published once per request | §2.1 |
| E2 | Canonical identity, no name fallback, server-canonical `/select`, mapped counterparts | §2.4 · A8c |
| E3 | One source, no arbitration or `_doing_it_wrong()` | §2.1 |
| E4 | No cap; threshold 25 is a tunable constant; own uncapped codec; `allowedCount` instead of a payload above it | §4.2 |
| E6 | Hidden-but-canonical, explicitly written values; Blocks needs address-store write + server normalisation | §4.3, §4.4 |
| E7 | Region derived from the item (`state`), bypassing `regionFieldRemoved` in city mode; a valid WC state code or free text kept | §4.1, §4.3 |
| E8 | Postcode policy kept; a rejected address's old postcode discarded before prefill | §4.1 |
| E9 | Checkout/cart-only scope; My Account/admin/API unrestricted; trusted-enough separation by `context` + submit enforcement | §4.6–§4.7 |
| E11 | Multi-country: filter the items by the chosen country using WooCommerce's own country policy; never force another country | §4.4 |
| E12 | Clear street/postcode of a rejected city in the checkout view (classic prefill + Block store); never touch stored profile data | §4.5 |
| E13 | No duplicate plugin-only gate: the shared package gate validates each package's own destination; package/chain disagreement tested | §6 |
| E15 | Pickup gate is mandatory work (F6) | §8 case 5 |
| E16 | ANDed with per-method `City_Limit`, no extra warning UI | §8 case 6 |
| E17 | Submit enforcement is the order-placement guarantee; no `package_rates` stripping; third-party pre-submit rates are not intercepted | §8 case 9 |
| E18 | Release in framework **2.0.26** itself | §10 |
| E19 | Mapper contract = §2.3; fixtures for the three named places plus the nested-settlement shape | C14, F2 |

**UNVERIFIED summary:** the block address form's handling of a `hidden` field's value (F5 probe); DaData settlement-level answers for the three example places (C14 fixtures); which pickup sources
carry a point region (F6 fixtures — a point without region discriminators is rejected); whether WooCommerce's classic form renders a `type=hidden` city row (not relied on — CSS/JS visibility);
the framework resolver choosing the highest copy for every installed plugin (READ in the resolver, not exercised here).

**Needs the operator:** nothing blocks the work. P1–P4 are small product details with a stated default.

## 13. How the Codex review (r1) was resolved

| # | Finding | Resolution |
|---|---|---|
| 1 | Name matching admits homonyms and forged payloads | §2.4: key equality for the same provider, mapped counterpart or exact country + own name + state for others, no name fallback, server-canonical `/select`; A8c |
| 2 | Compaction drops the DaData `locations` scope | §3.1: own codec keeps the scoping-`raw` allowlist, `fias_id` required, round-trip test; A8d |
| 3 | Nested settlement posted as its parent | §2.3: `own_locality()`, per-item `value`, exact-itself semantics (P3), mapper untouched |
| 4 | Clients do not behave as assumed | §4.3: active-chain presentation per surface, native Block fields locked, state derived past `regionFieldRemoved`, address-store clearing; K6 removes the payer constraint |
| 5 | Forcing only the Block order is too late | §4.4: normalisation at cart-update, checkout-update-customer and draft seams before totals; final validation with customer/package/order agreement; A8a/A8b split |
| 6 | «Адрес» vs the pickup policy | §4.2a: `effective()` override + server guard; A18 |
| 7 | Foreign pickup points | §8 case 5: mandatory gate, card F6; A11b |
| 8 | Additional fail-open decisions | §7 matrix: only mode-off, no licence (K7) and an empty list (P2) switch it off; key/provider/outage keep the saved list (K8); re-resolution cache/negative cache/backoff in §2.4 |
| 9 | My Account separation | §4.6–§4.7: `context`, suspended restriction for the account config; A19 |
| 10 | Unnecessary later release | K1, §10: framework 2.0.26 itself |
| 11 | `show_if` string vs boolean | §3.1: `value => true`; A16 |

## Related

- [2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) — the v2 spec this extends; D1–D16, §2.3 data flow, §2.5 outbound-call rule, §6 checkout, §2.6 licence gate.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the customer chain, staleness gate and default locality read in §2 and §4.5.
- [2026-10-04-sp11-block-checkout-design.md](2026-10-04-sp11-block-checkout-design.md) — block checkout adapter, `RouteException` pattern used in §4.4.
- [2026-08-12-location-provider-design.md](2026-08-12-location-provider-design.md) — provider capabilities and the DaData `locations` filter behind §4.1 and §3.1.
- [../adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — why the hide toggle cannot depend on a row count and why a toggle's `show_if` value is boolean (§3.1).
