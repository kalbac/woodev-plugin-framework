# «Доставка в пределах города» (range shipping 2.0.0) — design

**Date:** 2026-11-05. **Status:** draft r3 — **rewritten after the operator's redirect of 05.11.2026** (§0, binding): the list of places is the
**granularity of the checkout's settlement choice and of DaData's address suggestions**, not a delivery boundary. The delivery boundary stays
where it already is — the range method's own distance grid. r1/r2 designed a store-wide server-side restriction; §9 lists what was removed and why.
Nothing in this revision waits for the operator.
**Target:** the same release `2.0.0` of «Расчёт стоимости доставки за расстояние» (plugin id `range_rate`) as
[2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) (called **v2 spec** below; its D1–D16 are binding). Two repos change:
this framework (small, **in framework 2.0.26 itself — still unreleased, draft release PR #1606**) and `kalbac/woocommerce-range-shipping` (settings
and publication).

**Evidence labels:** **READ** (code or document read), **UNVERIFIED** (reason given — a rig or fixture step proves it). `fw:` = this repo's
`woodev/shipping-method/`; `plugin:` = `kalbac/woocommerce-range-shipping` at `f7ec676`.

## 0. The request and the decisions

Operator's request, 05.11.2026:

> Режим «В пределах города». Есть магазины, у которых доставка только в пределах своего города или плюс близлежащих городов.
> Обычно это доставка еды. Нам нужен такой режим, и работать он должен на весь сайт, а не для конкретного метода. В основных
> настройках плагина должна быть опция «Доставка в пределах города» с ясным объяснением, для чего эта опция и что она делает.
> При её включении появляется возможность добавлять список городов, минимум один город, максимум не ограничен. Города выбираются
> из списка и просто добавляются в общий список. Если город только один, то так же есть опция «Скрывать поле населённый пункт»:
> при её включении на чекауте поля «Населённый пункт» нет, оно всегда предзаполнено указанным городом из настроек. Если городов
> больше одного, то на чекауте список только из этих городов. Магазинщику при включении этой опции явно говорим, что если они
> используют другие службы доставки, например СДЭК или Почта РФ, то их покупатели не смогут выбрать город вне списка. Поле
> «Адрес» остаётся обязательным, иначе не доставить заказ.

Operator's redirect, 05.11.2026 (binding, overrides r1/r2's framing):

> Город, который выбирается из списка, — это не граница метода доставки, а всего лишь определение гранулярности для подсказок.
> Ограничение настраивается самим методом доставки. Если в магазине доступна только Москва, покупатель из подсказок не сможет выбрать
> адрес вне этого города — это не даст сделать сама DaData. А если максимальный радиус доставки 10 км, а покупатель ввёл адрес в 11 км
> от точки отправления, то уже сам метод не даст стоимость.

| # | Decision | Source |
|---|---|---|
| K1 | The feature ships **in plugin 2.0.0**; its framework part ships **in framework 2.0.26 itself** — no later framework release | operator |
| K2 | A list item is **any settlement** at DaData's city/settlement level (Химки, пос. Коммунарка, рп Черкизово), not only a city | operator |
| K3 | Settings on a **new sub-tab «Доставка в городе»**; sub-tabs become **Склады · Сервисы · Доставка в городе · Дополнительно** | operator |
| K4 | A **plugin setting that acts store-wide**: the checkout's settlement field offers only the listed places, whichever shipping method is chosen | operator |
| K5 | Classic checkout is complete in the release; block-checkout gaps may follow it **only if classic is complete** | operator rule |
| K6 | Only the **delivery** address gets the closed choice — the address the order ships to; for a `billing_only` shop that is billing. The payer's billing city stays free when «Доставить по другому адресу» is used | operator |
| K7 | **No licence in force → the feature is OFF** (the checkout behaves as without it) and the settings tab says so plainly | operator |
| K8 | **DaData key removed or DaData down → the settlement field still offers only the saved places**; only address suggestions stop | operator |
| K9 | Sub-tab name «Доставка в городе»; any settlement allowed | operator |
| K10 | **The list is presentation granularity, not a boundary.** No store-wide server-side city validation; the delivery boundary is the shipping method's own (the range method's distance grid) | operator redirect |
| K11 | **A city of federal significance** (Москва, Санкт-Петербург, Севастополь) **includes all its municipal districts**: «Москва» covers Новая Москва (пос. Коммунарка …) and Зеленоград. Replaces r2's open P3 | operator redirect |

## 1. Goal, scope, non-goals

**Goal.** A food-delivery shop switches one option on and lists its places. From then on the checkout's «Населённый пункт» is a choice of only
those places (or hidden and prefilled when there is one), and DaData's address suggestions stay inside the chosen place. Whether an address is
actually deliverable is decided, as today, by the shipping method — for the range method, its maximum distance.

| In scope | Non-goals (named once, not built) |
|---|---|
| Settings sub-tab, storage, validation, notices (§3) | A server-side «outside the list» rejection of orders, packages or pickup points (§9) |
| Checkout config + both clients: closed settlement choice, hidden single place, own value of a nested settlement (§4) | Restricting the payer's billing city (K6) |
| DaData `locations` scoping to the chosen place, incl. federal cities (K11, §4.3) | My Account, the order-edit screen, REST/API order creation — unchanged |
| Offline behaviour of the choice (K8) and licence behaviour (K7) (§5) | Radius / polygon zones (the range method's grid already is one) |

## 2. The mechanism — reuse first

READ — what already exists and is reused unchanged:

| Existing piece | Where (READ) | Reused for |
|---|---|---|
| The checkout location config, incl. a per-country `popular` list in the `/suggest` wire shape `{ key, label, level, record }` that the classic cascade already renders before typing | `fw: checkout/class-checkout-config.php` `build_location_block()` (1126); `location/class-location-service.php` `get_popular_settlements_for_country()` (3455); `assets/js/frontend/location-cascade.js` `popularFor()` (1900) | The closed list is served in the same shape, so a pick is byte-identical to a search pick and goes through the same `/select` |
| `/location/select` → the customer chain record | `fw: rest-api/class-location-controller.php` `handle_select_request()` (1402) | A pick writes the chosen place as the chain's settlement record, exactly as today |
| DaData address suggestions scoped to the chain's settlement by an exact `locations` filter built from the record's FIAS ids | `fw: location/providers/class-dadata-provider.php` `build_locations_constraint()` (1106), `native_parent_ids()` (1188) | «Suggestions stay inside the chosen place» — the operator's «это не даст сделать сама DaData» |
| The **fixed default locality** (policy `fixed` + record): seeds an implicit chain record; the classic client prefills and locks from `defaultLocality` | `fw: location/class-location-provider-registry.php` `get_default_locality_policy()` (2025), `get_default_locality_record()` (2047); `class-location-service.php` `resolve_default()` (2147); `location-cascade.js` `defaultLocalitySource()` (6074) | The one-place case: the single item **is** the fixed default while the mode is on |
| The `location-picker` column of a `records-table` control (DaData, level `settlement`) | `fw: settings-api/class-control.php:118,128` (F1, 2.0.26) | The settings list — the same control the warehouse address column uses (v2 spec D3) |
| The range method's distance grid | `plugin:` v2 spec §2.3 | The delivery boundary (K10) — untouched |

**One publication point (framework).** A new filter `woodev_location_settlement_choices` —
`apply_filters( 'woodev_location_settlement_choices', [ 'records' => [], 'hide_field' => false ] )`, `records` a `Location_Record[]`, empty = feature
off — read once per request and memoised in a static so every plugin's `Location_Service` facade sees the same answer. The range plugin is its only
source; with one source there is no arbitration. A second filter, `woodev_location_default_locality`, applied in `get_default_locality_policy()` /
`get_default_locality_record()`, lets the framework make the single item the fixed default (§4.2) **without writing the shop's «Локация» settings**.

## 3. Settings (plugin)

### 3.1 Fields and storage

New sub-tab **«Доставка в городе»** (section id `city_mode`) between «Сервисы» and «Дополнительно»: one more `Settings_Section` in
`Range_Plugin::get_tab_settings_providers()` (`plugin: includes/class-range-plugin.php`), handler
`Woodev\Range_Shipping\City_Mode\City_Mode_Settings extends \Woodev_Abstract_Settings`, modelled on `Warehouse_Settings` (READ
`plugin: includes/warehouses/class-warehouse-settings.php`). Options (namespace `range_rate`):

| Setting | Control | Option | Autoload | Notes |
|---|---|---|---|---|
| `city_mode` | toggle | `woodev_range_rate_city_mode` | yes | master switch, default **off** |
| `city_mode_cities` | `records-table`, one `location-picker` column «Населённый пункт» (DaData, level `settlement`) | `woodev_range_rate_city_mode_cities` | no | JSON rows `{ "city": "<Location_Record JSON>" }`, decoded with `Records_Table::decode()` + `Location_Record::from_array()` |
| `city_mode_hide_field` | toggle | `woodev_range_rate_city_mode_hide_field` | yes | «Скрывать поле «Населённый пункт»», default **off** |

**Store the record whole — never through `City_Limit::encode()`.** That codec nulls `raw`, and `native_parent_ids()` reads the FIAS ids **only** from
`raw`; a compacted «рп Черкизово» would scope suggestions by the parent city's *name* instead of its own `settlement_fias_id` (measured by the r1
critic). The row keeps the record as the picker produced it; a unit test round-trips save → reload → `locations` and asserts equality.

**Conditions.** `city_mode_cities` and `city_mode_hide_field` carry `show_if = [ 'setting' => 'city_mode', 'operator' => '=', 'value' => true ]`
— a **boolean** `true`, as ADR-008 requires for a toggle (a string `"yes"` evaluates false and `filter_visible_values()` then strips both fields from the
save). The hide toggle cannot depend on the row count (ADR-008 has no such operator), so it is visible whenever the mode is on, its text says it works
for one place, and it takes effect only when the list has exactly one.

### 3.2 Validation on Save (server)

| Rule | Message |
|---|---|
| Mode on, list empty | «Добавьте хотя бы один населённый пункт — иначе покупателю будет нечего выбрать.» |
| A row is not a DaData settlement-level record with a name, a country and a `fias_id` | «Выберите населённый пункт из подсказок.» |
| The same place twice (equal keys) | «Этот населённый пункт уже в списке.» |
| Mode off | the list is kept and not validated |

No upper bound (operator). A list emptied by a crafted write is read as «feature off» and the tab says so (§3.4).

### 3.3 The page text

```
 Доставка в городе ─────────────────────────────────────────────────────────────
 [x] Доставка в пределах города
     Включите, если вы доставляете заказы только в своём городе или ещё в нескольких
     ближайших — например, доставка еды или цветов. На оформлении заказа в поле
     «Населённый пункт» покупатель сможет выбрать только населённые пункты из вашего
     списка, а подсказки адреса будут только внутри выбранного. Это действует на весь
     магазин сразу. Насколько далеко вы возите, по-прежнему решает способ доставки —
     например, максимальное расстояние в его настройках.
     Список касается адреса, куда доставляется заказ. Плательщик может жить в другом
     городе — для этого ему достаточно выбрать «Доставить по другому адресу».

   ┌─ Важно ──────────────────────────────────────────────────────────────────┐
   │ Если в магазине есть другие службы доставки — например, СДЭК или Почта   │
   │ России, — их покупатели тоже не смогут выбрать город вне вашего списка.  │
   │ Включайте режим, только если вы действительно не отправляете заказы      │
   │ дальше.                                                                  │
   └──────────────────────────────────────────────────────────────────────────┘

   Населённые пункты
   Выбирайте из подсказок — подойдёт город, посёлок или район, например Химки,
   пос. Коммунарка. Москва, Санкт-Петербург и Севастополь включают все свои районы
   и поселения (в том числе Новую Москву и Зеленоград). В обычном городе посёлок,
   который покупатель должен видеть отдельно, добавьте отдельной строкой.
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

Empty table text: «Добавьте хотя бы один населённый пункт — город, посёлок или район города.» The «Важно» box is the operator's explicit-warning
requirement; it shows whenever the switch is on. Admin strings are Russian msgids in the plugin; the few buyer strings the framework adds (placeholder
«Выберите город», msgid "Choose your city") are English msgids with a Russian catalogue entry (storefront rule #567).

### 3.4 Publication and notices

`City_Mode\City_Mode` (plugin) hooks `woodev_location_settlement_choices` and `woodev_location_default_locality` **only when the switch is on, the list is
non-empty and `License_Gate::is_in_force( Range_Plugin )`**. Nothing else in the plugin touches the checkout (except the key-removed fallback, §5).
Notices on the tab are computed on render, never stored:

| Condition | Notice |
|---|---|
| Licence not in force (K7) | «Режим «Доставка в пределах города» сейчас не работает: лицензия не действует. Покупатели видят обычное поле «Населённый пункт» и могут выбрать любой город. Введите или продлите лицензионный ключ — режим включится сам.» |
| DaData key missing or DaData unreachable (K8) | «Подсказки адресов сейчас не работают: не указан ключ DaData или сервис недоступен. Покупатель по-прежнему выбирает город только из вашего списка, но адрес вводит без подсказок.» |
| Mode on, list empty (crafted write) | «Список пуст — режим не действует. Добавьте хотя бы один населённый пункт.» |

## 4. Checkout

### 4.1 Which section

The closed choice applies to the **delivery** section (K6): `Address_Target` (READ `fw: pickup/class-address-target.php`) — billing when
`wc_ship_to_billing_address_only()` or «Доставить по другому адресу» is off, shipping when it is on. The classic cascade already builds one active
chain and follows that toggle (`location-cascade.js::buildChain()`, ~978); the block chooser already belongs to the shipping form, or to billing for
`billingOnly && forcedBillingAddress` (`src/checkout-blocks/register.tsx`). The other section stays plain.

### 4.2 Config and behaviour

`Checkout_Config::build_location_block()` emits, when the filter answers with records and the config is built for the checkout or the cart
calculator — not for My Account: `enqueue_account_assets()` builds its own config (`checkout-handler.php:1338`) and simply does not ask:

| Key | Value |
|---|---|
| `allowed[country]` | the listed records of that country in the `popular` wire shape `{ key, label, level, record }` **plus `value`** — the text the City field shows and posts: the own locality name, `nested_settlement` when the record has one, else `settlement` (so «рп Черкизово» posts «Черкизово», not its parent «Пушкино» — READ `class-dadata-provider.php:1396-1430` maps the parent into `settlement`) |
| `hideSettlement` | `true` when the flag is on and exactly one place is listed |
| `allowCustomSettlement` | `false` while `allowed` is present (a free-text settlement would defeat the choice); the store setting is not rewritten |
| `levels[country].region` | `false` while `allowed` is present — the region is not asked; the pick writes the place's region into the native State/region field |

| Case | Classic (`location-cascade.js`) | Block (`src/checkout-blocks`) |
|---|---|---|
| **Several places** | The settlement node renders **only `allowed`** as a local list (the `popular` renderer path, filtered locally as the buyer types) and makes **no settlement-level `/suggest` call**, whatever the shop's field mode; placeholder «Выберите город». A pick calls `/select` as today | `locality-chooser.tsx` renders only `allowed` (local filtering for a long list) and makes no settlement `/suggest`; a pick calls `/select`, then writes City = `value` and State from the record's region — a city-mode branch in `mapping.ts::resolveNativeAddress()`, which today skips state when `regionFieldRemoved`; the branch writes it only where the shop has a region field |
| **One place, field shown** | The fixed default (§2) seeds the chain implicitly; the existing `defaultLocality` path prefills and locks the field | Native City prefilled with `value` and locked |
| **One place, hidden** | As above, and the settlement (and region) row is hidden by the framework's visibility mechanism (JS + CSS class, the way `address_field=hide_for_pickup` already hides a row — not `unset()`, so the value still posts) | The chooser is not mounted; the client writes City/State/Country of the delivery address into the cart address store (Blocks' hidden field cannot be relied on to post — UNVERIFIED, F3 probe) |
| **Address pick** | Street/house/postcode filled as today; City/State are **not** rewritten from the address record (that backwards-fill would put «Пушкино» over «Черкизово») | Same |
| **«Адрес»** | Untouched: still shown and required wherever the shop's own «Поля» policy shows it; city mode never hides or relaxes it | Same |

### 4.3 Address suggestions inside the chosen place

After a pick the chain's settlement record is the listed record, so `/suggest` for the address level already sends DaData an exact `locations`
filter from its FIAS ids (READ `build_locations_constraint()` → `native_parent_ids()`). Per kind of place:

| Place | `locations` sent | Result |
|---|---|---|
| Ordinary city (Химки) | its `region_fias_id` + `city_fias_id` (as today) | Streets of Химки, including settlements DaData files under the city |
| Nested settlement (рп Черкизово) | + its own `settlement_fias_id` | Only Черкизово |
| **City of federal significance** (Москва, Санкт-Петербург, Севастополь — K11) | **only** its `region_fias_id` (new rule in `native_parent_ids()`: when the record's region is itself the city — DaData `region_type` «г» — the city id is dropped) | The whole city incl. Новая Москва and Зеленоград. UNVERIFIED: that DaData's Moscow record carries a `city_fias_id` and the current filter therefore excludes Зеленоград — the C15 fixture records both before F2 merges; if the current filter already covers them, the rule is a no-op and is dropped |

This is the operator's mechanism: a buyer who uses the suggestions cannot pick an address outside the place. A buyer who ignores them and types
a far-away street gets no range rate, because the distance grid refuses it (K10).

### 4.4 Returning customer with a saved other city

While `allowed` is present, a chain settlement record whose key is not listed, and WooCommerce's saved city text (`customer_saved_city()`, READ
`class-location-service.php:1690`) that names no listed `value`, are **ignored for the checkout view** — one added rule in
`is_customer_record_stale()` (1160) and a guard in the saved-city reads. With several places the field shows the placeholder; with one place the fixed
default takes over (the saved-city early return in `get_customer_record()` would otherwise block it — READ 300-469). The street and postcode prefilled
from that rejected city are cleared in the checkout view (classic `handle_checkout_get_value()`, 725; block: the cart address store). The customer's
stored My Account address is never edited. This is presentation, not validation: nothing is rejected at submit.

Related one-line fix: `record_names_city()` (staleness rule c, READ 1303) compares the native city with `settlement()` only, so a nested record whose
native City correctly says «Черкизово» is dropped as stale on a Store API request (measured by the r2 critic); it also accepts the `nested_settlement` name.

## 5. Licence, key and outage

| State | Checkout |
|---|---|
| Mode off | the shop's own «Локация» settings — nothing changed |
| **Licence not in force** (K7) | the filters are not published → the shop's own settings; the range method itself is hidden by the framework's licence gate (v2 spec §2.6); notice §3.4 |
| DaData **down, key present** (K8) | the choice is served from the config, so it works unchanged; `/select` stores the listed record (the DaData provider's `enrich_selected_record()` is the identity — READ by the r2 critic); only address suggestions fail |
| DaData **key removed** (K8) | the location layer is inactive (`Location_Service::is_active()`, READ 274-298): no location config, no cascade. **Classic:** the plugin turns the delivery City into a WooCommerce `select` of the listed `value`s through `woocommerce_checkout_fields` — only while the layer is inactive, so it never meets the cascade. **Block:** the native City stays a text field — a K5 gap (§8). Notice §3.4 |
| List empty (crafted) | treated as off; notice §3.4 |

No outbound call is added anywhere: the choice is local, `/select` already exists, suggestions are DaData's existing call.

## 6. Interaction with the range method and other carriers

- **Range method:** unchanged. It prices by distance (v2 spec §2.3); an address beyond its maximum distance gets no rate. With one place the chain is
  the item's DaData record, so a cart-page quote without a street takes the settlement point, precision `approximate`, with the v2 spec §6.2 notice.
- **Other Woodev carriers (СДЭК, Яндекс, Почта, Ozon):** they read the same checkout field and chain, so their buyers also choose only listed places —
  what the «Важно» box tells the merchant. Their pickup maps are not restricted (§9).
- **Non-Woodev methods:** not touched.
- **Older framework copies in other plugins:** irrelevant — the loader runs the highest copy (READ `class-framework-resolver.php`); `Range_Plugin`
  bundles ≥ 2.0.26.

## 7. Cards

`F` = framework, `C` = plugin; numbers continue the v2 spec's (F1, C1–C13). Issues are created by the coordinator when the spec is approved. F2–F3
are part of **framework 2.0.26 itself** (draft release PR #1606); the plugin release bundles the matching `woodev/` tag.

| Card | Scope | Depends | Size | Critic |
|---|---|---|---|---|
| **F2** | Framework PHP: `woodev_location_settlement_choices` (static-memoised) and `woodev_location_default_locality` filters; `build_location_block()` keys of §4.2 for checkout/cart configs only; the federal-city rule in `native_parent_ids()` (§4.3); the returning-customer rule + saved-city guard + view clearing (§4.4); the `record_names_city()` nested fix. Unit tests: config with 1/3 places and the hidden flag, account config unchanged, nested `value`, federal vs ordinary vs nested `locations`, saved Tula ignored, fixed default from the filter without touching the stored option | — | M | Codex |
| **F3** | Framework clients: classic cascade (closed local list, no settlement `/suggest`, hidden row, no backwards-fill, region from the pick) and block chooser (closed list, native City/State write incl. the `resolveNativeAddress()` branch, hidden single writes the address store, rejected-city clearing); Jest + a rig check by the worker; the block hidden-field probe | F2 | M | Codex |
| **C14** | Plugin: `City_Mode_Settings` (sub-tab, three options, boolean `show_if`, validation §3.2, texts §3.3), `City_Mode` (licence-gated publication), notices §3.4, the key-removed classic `select` fallback (§5), unit tests. The settings shell can start at once; publication codes against F2's filter signatures | F2 (interface) | M | Codex |
| **C15** | Plugin: recorded DaData fixtures (Москва with Зеленоград and Коммунарка addresses, Химки, рп Черкизово), rig acceptance §8 on classic + block with a report and screenshots on disk, docs (`docs/CURRENT-STATE.md`, product page text, materials package), Russian catalogue | C14, F3 | M | operator reads the report |

**Waves.** W-A: F2 ∥ C14. W-B: F3. W-C: C15. Inside the v2 spec's W5 slot; C13 (e2e, materials) waits for C15 because the product text describes this tab.

## 8. Acceptance on the rig

WordPress rig with DaData, the range method (maximum distance set), a flat rate and, where available, СДЭК; WC current and current−1. **Classic and
block for every row**; a row passes when the placed order's City/State/street are right, not just the chooser label.

| # | Scenario | Pass when |
|---|---|---|
| A1 | 1 place hidden (Москва) | No City/region row in the delivery section; «Адрес» shown and required; address suggestions only inside Москва — **including Зеленоград and Коммунарка streets** (K11); the order says Москва |
| A2 | 1 place, flag off | City row visible, prefilled and locked to Москва |
| A3 | 3 places (Москва, Химки, пос. Коммунарка) | The field offers exactly these three; no settlement `/suggest` request in the network log; after picking Химки, suggestions only in Химки; picking an address does not change City |
| A4 | Nested settlement (рп Черкизово listed) | City posts «Черкизово»; suggestions only in Черкизово; the chain survives placement (not dropped as stale); after saving and reloading the settings the `locations` filter is still the exact `settlement_fias_id` |
| A5 | Range boundary (K10) | An address inside the place but beyond the maximum distance gets no range rate; an address inside the distance gets the exact price; the cart quote with one hidden place shows the approximate price and notice |
| A6 | Returning customer, saved city Тула, 3 places / 1 place hidden | 3 places: placeholder, Tula street and postcode not prefilled. 1 place: Москва prefilled, no Tula street. My Account address unchanged |
| A7 | `billing_only` shop, and a separate shipping address with «Доставить по другому адресу» toggled | The closed choice follows the delivery section on every toggle; the payer's billing city outside the list is accepted |
| A8 | Licence not in force, then renewed | Ordinary City field, any city; range method hidden; the §3.4 notice; renewal restores the mode on the next request |
| A9 | DaData key removed; DaData unreachable with the key present | Key removed — classic: City is a `select` of the listed places, no suggestions, notice; block: text City (K5 gap, recorded). Unreachable — the choice works, only suggestions fail |
| A10 | Settings: save with the mode on and an empty list, with a duplicate, with a row without `fias_id`; toggle the mode on | Blocking errors of §3.2, nothing saved; the two dependent fields appear on toggle and are saved |
| A11 | Mode switched off after use; My Account address edit with the mode on | Off: the shop's own «Локация» behaviour, no leftovers. My Account: the unrestricted picker as today |
| A12 | Another carrier active (СДЭК) | Its buyers also pick only listed places; its pickup map is not restricted (§9) |

**Block gaps allowed after release (K5),** only if classic is complete: the key-removed `select` fallback (block keeps a text City) and the rejected-city
placeholder hint. Not deferrable: the closed choice, the hidden single place, the address-store write and clearing, the nested `value`.

## 9. What r3 removed, and why

Operator redirect of 05.11.2026 (K10): the list is the granularity of the choice and of DaData's suggestions; the shipping method owns the boundary.
Everything below existed only to make the list a store-wide boundary, so it goes:

| Removed (r1/r2) | Why it is no longer needed |
|---|---|
| `Allowed_Localities` value objects, registry, `resolve()` decision function, canonical-identity rules, same-name refusal | No server decision is made from the list; the clients render the listed records and `/select` stores them as today |
| Server-side normalisation and validation on every path (classic `checkout_process`, Store API cart / checkout POST / draft seams, `validate_order`, cart calculator), customer/package/order agreement | The range method's distance grid refuses far addresses; other methods keep their own rules (K10) |
| Package gate in `Shipping_Method::is_available_for_package()` and the `outside_allowed_localities` reason | Same — the method's own limits decide |
| Chain substitution on consumption, account-context poisoning defence, `/select` 422 | Without a boundary a forged record gains nothing beyond what it gains today |
| My Account `context` parameter and suspended config | The account config simply never asks for the list (§4.2) |
| Pickup-point gate (F6: G1/G2/G3, viewport-source unavailability, open P5) | Pickup maps are not restricted; a point is the carrier's matter |
| «Адрес» override over `hide_for_pickup` (`override_field_policy()`, two projections) | City mode never hides «Адрес»; the shop's own «Поля» policy applies unchanged |
| Mapped counterparts for non-DaData providers (`map_allowed_item()`, result type, budget, cache, cron) | The list is DaData's (the settings picker is DaData); suggestions and the chain stay DaData's |
| Provisional P1–P5 | P3 decided by the operator (K11); the rest vanished with the boundary |
| Cards F4–F6, C16 | Merged into F2/F3/C14/C15 |

Still applied from the reviews: the record is stored whole (r1 finding 2), a nested settlement posts its own name and survives the staleness rule
(r1/r2 finding 3), the clients' real behaviour per surface (r1 finding 4), release in 2.0.26 (r1 finding 10), boolean `show_if` (r1 finding 11).

## Related

- [2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) — the v2 spec this extends; D1–D16, §2.3 distance pricing, §2.6 licence gate, §6 checkout.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the customer chain, staleness gate and default locality reused in §2 and §4.4.
- [2026-08-24-popular-settlements-design.md](2026-08-24-popular-settlements-design.md) — the popular-list wire shape the closed list reuses (§4.2).
- [2026-08-12-location-provider-design.md](2026-08-12-location-provider-design.md) — provider capabilities and the DaData `locations` filter behind §4.3.
- [../adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — why a toggle's `show_if` value is boolean and the hide toggle cannot depend on a row count (§3.1).
