# «Доставка в пределах города» (range shipping 2.0.0) — design

**Date:** 2026-11-05. **Status:** draft r7.1 (06.11.2026) — history in the [revision log](#revision-log). **r7** applies the operator's decision of
06.11.2026 (K15, #1642): every listed place belongs to a **country**, the admin picks the country first, and the checkout applies city mode **per the
customer's country** — a country with no listed place keeps the ordinary field. **r7.1** folds in the r7 critic's seven findings (the data wires,
hook registration, billing-only fallback, pickup guard, the §3.2 row; the checkout-view country, finding 5, was dropped by the coordinator — §4.2) and records the operator's confirmation of every
§10 item (K16, K17). Its implementation plan is
[plans/2026-11-06-1642-city-mode-country.md](../plans/2026-11-06-1642-city-mode-country.md). r3 was **rewritten after the operator's
redirect of 05.11.2026** (§0, binding): the list of places is the **granularity of the checkout's settlement choice and of the active provider's address
suggestions**, not a delivery boundary. The delivery boundary stays where it already is — the range method's own distance grid. r1/r2 designed a
store-wide server-side restriction; §9 lists what was removed and why. **r4** replaced K11 («a federal city includes its districts») with the operator's
**K11′**: the plugin hands the listed record to the provider and decides nothing about what a city contains. **r5** applies the operator's late inputs of
05.11.2026 (K12–K14): a record carries its **FIAS id** wherever its provider knows one (the CDEK provider stops dropping `fias_guid` — card E1, a separate
`woocommerce-edostavka` PR), and DaData address suggestions for such a record are scoped **by that id** (city OR settlement); FIAS is Russia-only, so
outside Russia the scope is country + the record's own locality name, for every provider. **r5.1** applies the r5 critic's four must-fix findings (revision
log). **r6** applies the operator's decision after his rig look of 06.11.2026 (§4.2 behaviour table, card F5 #1627): one place is plain text, several
places are a plain dropdown, the State row is hidden in both. Nothing in this revision blocks on the operator; §10 records his decisions on the
choices that were provisional (all confirmed 06.11.2026).
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
| K2 | A list item is **any settlement** at the active provider's city/settlement level (DaData: Химки, пос. Коммунарка, рп Черкизово; CDEK: a CDEK city), not only a city | operator |
| K3 | Settings on a **new sub-tab «Доставка в городе»**; sub-tabs become **Склады · Сервисы · Доставка в городе · Дополнительно** | operator |
| K4 | A **plugin setting that acts store-wide**: the checkout's settlement field offers only the listed places, whichever shipping method is chosen | operator |
| K5 | Classic checkout is complete in the release; block-checkout gaps may follow it **only if classic is complete** | operator rule |
| K6 | Only the **delivery** address gets the closed choice — the address the order ships to; for a `billing_only` shop that is billing. The payer's billing city stays free when «Доставить по другому адресу» is used | operator |
| K7 | **No licence in force → the feature is OFF** (the checkout behaves as without it) and the settings tab says so plainly | operator |
| K8 | **DaData key removed or DaData down → the settlement field still offers only the saved places**; only address suggestions stop | operator |
| K9 | Sub-tab name «Доставка в городе»; any settlement allowed | operator |
| K10 | **The list is presentation granularity, not a boundary.** No store-wide server-side city validation; the delivery boundary is the shipping method's own (the range method's distance grid) | operator redirect |
| K11′ | **The plugin does not decide where addresses are searched — the provider does.** The merchant's chosen settlement record is passed to the active provider's own scoping **as-is**; there is no «federal city → region only» rule and no type-based special case. If the merchant lists only «Москва», addresses are searched in Москва *as the provider understands it*; Зеленоград (and Новая Москва, etc.) are **not** added automatically — the merchant adds them to the list himself. The settlement provider may be CDEK, and the plugin cannot tell which of its cities is federal, so any special rule would break. Replaces r3's K11 («federal cities include their districts») and r2's open P3. Measured 05.11.2026 (§4.3) | operator, 05.11.2026 (binding) |
| K12 | **A record keeps its FIAS id, whichever provider made it.** The CDEK provider keeps the dictionary row's `fias_guid` (and, as today, its coordinates) in the CDEK location record instead of dropping it (READ `woocommerce-edostavka` `includes/location/class-cdek-location-provider.php:22` «FIAS is not used»; the adapter already matches on it, `class-cdek-location-adapter.php:206`). It is a **separate `woocommerce-edostavka` card and PR (E1)**; the CDEK plugin release is not part of this wave | operator, 05.11.2026 late (s218 «City-mode r5 inputs») |
| K13 | **DaData address suggestions for a listed place with a FIAS id are scoped by that id — as a city OR as a settlement** (`locations` keys `city_fias_id`, then `settlement_fias_id`; §4.3). This is still K11′: the id is the place's own, handed on as-is, and DaData decides what lies under it | operator, 05.11.2026 late |
| K14 | **FIAS is Russia-only.** Outside Russia DaData's ids are OpenStreetMap/GeoNames ids and CDEK rows carry no `fias_guid`, so **every non-RU record, whichever provider made it, is scoped by country + the record's own locality name** — DaData's OSM/GeoNames ids are no longer used for scoping. A Russian record without a FIAS id takes the same name path. One provider-agnostic rule, decided in the framework's DaData provider, nothing in the range plugin (§4.3) | operator, 05.11.2026 late; non-RU DaData case settled by the coordinator 06.11.2026 (r5.1: the operator's input wins) |
| K15 | **A country per place (r7).** A listed place belongs to its record's own `country` (already stored — **no migration**). **Admin:** the add row asks «Страна» first, then «Населённый пункт», whose suggestions search **only that country**; the choices are WooCommerce «Доставка в страны» ∩ the countries the location provider chain suggests for (`location.countries`); exactly one choice → no dropdown, the country is filled in and shown as text; table rows `Страна — Населённый пункт — Изменить — Удалить`, **sorted by country**; a row whose country has left «Доставка в страны» is **kept and flagged** «страна не входит в доставку — на оформлении не показывается». **Checkout, classic and block, by the customer's country:** one place there → fixed text, «Регион» hidden; several → a list (classic select2, #1634); **none → the ordinary field with suggestions and region, city mode does not apply, and nothing at order placement requires a listed place**. The «Скрывать поле» inactivity condition («more than one place») is **counted per country**. Narrows K4: «store-wide» now means «for every listed country» | operator, 06.11.2026 (s220, #1642 variant 2) |
| K16 | **How K15 reads where the code forced a reading** (were §10 items 3–7): «Скрывать поле» counted per country is **`min`** — the switch is inactive only while **every** listed country has more than one place (RU 3 + KZ 1 → active, it hides the KZ field only); the country choices **keep countries without address suggestions** (AM AZ KG TJ TM on the measured rig — the place works, the street is typed freely); the **per-country copy** of §3.3 r7 and §6 and the **«every row flagged» notice** of §3.4 ship as written; the block's «several places» stays a **native `<select>`** (#1634 changed classic only) | operator, 06.11.2026 (s220: «всё по рекомендациям») |
| K17 | **CDEK rows saved before E1 get no migration and no notice** (they stay name-scoped, branch 3, until re-picked), and **E1 ships with the next regular CDEK release**, not as its own hotfix (were §10 items 1–2) | operator, 06.11.2026 (s220: «всё по рекомендациям») |

## 1. Goal, scope, non-goals

**Goal.** A food-delivery shop switches one option on and lists its places. From then on the checkout's «Населённый пункт» is a choice of only
those places (or hidden and prefilled when there is one), and the active provider's address suggestions stay inside the chosen place, as that
provider scopes it (K11′). Whether an address is
actually deliverable is decided, as today, by the shipping method — for the range method, its maximum distance.

| In scope | Non-goals (named once, not built) |
|---|---|
| Settings sub-tab, storage, validation, notices (§3) | A server-side «outside the list» rejection of orders, packages or pickup points (§9) |
| Checkout config + both clients: closed settlement choice, hidden single place, own value of a nested settlement (§4) | Restricting the payer's billing city (K6) |
| Handing the chosen record to the active provider's own scoping — DaData `locations` by the record's own ids, by its FIAS id (K13), or by name outside Russia (K14) — with no plugin-side rule (K11′, §4.3) | My Account, the order-edit screen, REST/API order creation — no city-mode restriction (the shared nested-locality comparison fix of §4.4 still applies to the order editor) |
| Offline behaviour of the choice (K8) and licence behaviour (K7) (§5) | Radius / polygon zones (the range method's grid already is one) |

## 2. The mechanism — reuse first

READ — what already exists and is reused unchanged:

| Existing piece | Where (READ) | Reused for |
|---|---|---|
| The checkout location config, incl. a per-country `popular` list in the `/suggest` wire shape `{ key, label, level, record }` that the classic cascade already renders before typing | `fw: checkout/class-checkout-config.php` `build_location_block()` (1126); `location/class-location-service.php` `get_popular_settlements_for_country()` (3455); `assets/js/frontend/location-cascade.js` `popularFor()` (1900) | The closed list is served in the same shape, so a pick is byte-identical to a search pick and goes through the same `/select` |
| `/location/select` → the customer chain record | `fw: rest-api/class-location-controller.php` `handle_select_request()` (1402) | A pick writes the chosen place as the chain's settlement record, exactly as today |
| Address suggestions scoped to the chain's settlement by the **provider's own** scoping: DaData builds an exact `locations` filter from the record's FIAS ids; a CDEK record reaches DaData's address search as a city-name filter, and the `settlement` retry when the city answer is empty | `fw: location/providers/class-dadata-provider.php` `build_locations_constraint()` (1106), `native_parent_ids()` (1188), `suggest()` retry (~472), `foreign_locality_parent()` (1221) | «Suggestions stay inside the chosen place» — the operator's «это не даст сделать сама DaData». r5 adds **one** branch — a foreign record that carries a FIAS id (K12) is scoped by that id (K13) — and r5.1 sends every non-RU record by name (K14, §4.3) |
| The **fixed default locality** (policy `fixed` + record): seeds an implicit chain record; the classic client prefills and locks from `defaultLocality` | `fw: location/class-location-provider-registry.php` `get_default_locality_policy()` (2025), `get_default_locality_record()` (2047); `class-location-service.php` `resolve_default()` (2147); `location-cascade.js` `defaultLocalitySource()` (6074) | The one-place case: the single item **is** the fixed default while the mode is on |
| The `location-picker` column of a `records-table` control (the **active** location provider, level `settlement` — `class-control.php:107-110`) | `fw: settings-api/class-control.php:118,128` (F1, 2.0.26) | The settings list — the same control the warehouse address column uses (v2 spec D3). Its records come from whichever provider is active (DaData or CDEK) |
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
| `city_mode_cities` | `records-table`, one `location-picker` column «Населённый пункт» (active provider, level `settlement`) | `woodev_range_rate_city_mode_cities` | no | JSON rows `{ "city": "<Location_Record JSON>" }`, decoded with `Records_Table::decode()` + `Location_Record::from_array()` |
| `city_mode_hide_field` | toggle | `woodev_range_rate_city_mode_hide_field` | yes | «Скрывать поле «Населённый пункт»», default **off** |

**Store the record whole — never through `City_Limit::encode()`.** That codec nulls `raw`, and `native_parent_ids()` reads the FIAS ids **only** from
`raw`; a compacted «рп Черкизово» would scope suggestions by the parent city's *name* instead of its own `settlement_fias_id` (measured by the r1
critic). The row keeps the record as the picker produced it (the provider's own record, handed on as-is — K11′); a unit test round-trips save → reload →
`locations` and asserts equality.

**Conditions.** `city_mode_cities` and `city_mode_hide_field` carry `show_if = [ 'setting' => 'city_mode', 'operator' => '=', 'value' => true ]`
— a **boolean** `true`, as ADR-008 requires for a toggle (a string `"yes"` evaluates false and `filter_visible_values()` then strips both fields from the
save). The hide toggle cannot depend on the row count (ADR-008 has no such operator), so it is visible whenever the mode is on, its text says it works
for one place, and it takes effect only when the list has exactly one.

**r7 — the country of a place (K15).** The table gains a **«Страна» column before «Населённый пункт»**. It is **derived, not stored**: its value is the
`country` of the record in the `city` cell, so the stored row stays `{ "city": "<Location_Record JSON>" }` and every row saved before r7 already has its
country (no migration). A new framework records-table column type `location-country` (plan WP-D) carries it:

| Column key | Value |
|---|---|
| `of` | `city` — the location-picker column whose record supplies the country and whose search the chosen country scopes |
| `options` | the countries a **new or edited** row may choose: `WC()->countries->get_shipping_countries()` ∩ `Location_Service::get_supported_countries()` (`location.countries`), as `City_Limit::country_options()` `{ code, name }` in WooCommerce's order |
| `in_scope` | the codes a stored row may carry **without the flag**: WooCommerce «Доставка в страны» only — not `location.countries`, so a removed DaData key (K8, every provider country gone) flags nothing |
| `default` *(r7.1, resolved by the framework, not declared)* | the code a new row starts on: `WC()->countries->get_base_country()` when it is among `options`, else the first option's code, `''` without options. Added to the column schema by `Field_Schema`; `window.woodevSettings` carries no store country, so the client cannot work it out itself |
| `names` *(r7.1, resolved by the framework, not declared)* | `code => WooCommerce country name` for every code in `options` **and** every country of the setting's stored rows (the `of` records' `country`) — so a flagged row whose country left both `options` and shipping still shows «Казахстан» and sorts by it; the code itself only where WooCommerce knows no name |

- **Form.** «Страна» comes first. Several `options` → a select; exactly one → no select, the country is shown as text and used; a new row starts on
  `default` (the store's base country when it is among `options`, else the first option). The chosen code is passed as `country` to **that row's** location picker
  (today every picker cell gets the control-wide `schema.country`, which the range plugin leaves empty, so the admin `/default-locality/suggest` route
  searches `resolve_default_country()` — the store's base country; see «Coverage» below). Changing the country of a row whose picked record is of
  another country clears that cell.
- **Table.** `Страна — Населённый пункт — Изменить — Удалить`; the «Страна» cell shows `names[code]` — the WooCommerce country name (the code when
  WooCommerce does not know it). Rows are **kept sorted by the country name** (stable within a country, so the merchant's order of places inside a country stays) — the
  table re-sorts the value on every add and edit, so what is saved is what is shown.
- **Flag.** A row whose record's country is not in `in_scope` keeps its place in the table and shows, in its «Страна» cell, the note
  «страна не входит в доставку — на оформлении не показывается». It is never deleted or refused (§3.2); the plugin does not publish it (§3.4).
- **«Скрывать поле».** Its `disabled_if` becomes a **per-country** row count: the framework `count` condition gains `per` (a `location-country`
  column id — rows grouped by their record's country, only countries in that column's `in_scope`) and `aggregate` (`min` / `max`), plan WP-D and an
  ADR-008 amendment. The aggregate is **`min`** (operator, K16) — the switch is inactive while **every** listed country has more than one place,
  because it then has nothing to hide. The save-time clearing in `City_Mode_Settings::update_value()` follows the same rule. **r7.1 — the server
  half needs the column declarations.** `Woodev_Setting::evaluate_conditions()` today receives only a flat list of records-table ids (third argument),
  so it cannot find the `of` cell or `in_scope`; both PHP save paths — `Woodev_Abstract_Settings::filter_visible_values()` and the composite
  handler's own copy (`class-composite-settings-handler.php`) — pass instead a map **records-table id → its column declarations**
  (`Woodev_Control::get_columns()`), built from the registered controls. A `count` without `per`, and a table without a `location-country` column,
  count every row exactly as today. Without this the browser would enable the switch for RU 3 + KZ 1 while the save strips it (plan WP-D).

**Coverage, measured 06.11.2026** (the rig, DaData + CDEK configured, CDEK the active provider, WooCommerce ships to all countries; read-only
`wp eval`, country codes only):

| | Countries |
|---|---|
| DaData `get_countries()` (`DEFAULT_COUNTRIES`, filter `FILTER_COUNTRIES`) | AM AZ BY KG KZ RU TJ TM UZ |
| CDEK provider `get_countries()` | AM BY KG KZ RU UZ |
| `location.countries` (`get_supported_countries()`, the union over the chain) = the r7 «Страна» choices on this rig | AM AZ BY KG KZ RU TJ TM UZ |
| settlement level — `provider_for_level( settlement, c )` | CDEK: AM BY KG KZ RU UZ · DaData (D15 fallback): AZ TJ TM |
| address level — `provider_for_level( address, c )` | DaData: BY KZ RU UZ · **none**: AM AZ KG TJ TM |

So every choice can add a place (the settlement level resolves in all nine), but in **AM, AZ, KG, TJ, TM** a listed place has **no address
suggestions** — the buyer types the street freely (DaData's GeoNames tier is city-only, `Dadata_Provider::get_countries()` docblock). That is the same
as checkout without city mode there; the choices keep these countries and the page text adds nothing (operator, K16). **What s219 saw** («a RU shop cannot add a foreign city through the
picker»): the picker had no country input at all — `records-table.tsx` hands every location cell the control's `schema.country`, empty for this
setting, and `perform_suggest()` then searches `resolve_default_country()` (RU on the rig), through CDEK, so «Алматы» found nothing. The «Страна»
select above is exactly the missing input; no provider change is needed.

### 3.2 Validation on Save (server)

| Rule | Message |
|---|---|
| Mode on, list empty | «Добавьте хотя бы один населённый пункт — иначе покупателю будет нечего выбрать.» |
| A row is not a valid settlement record: its JSON is refused by `Location_Record::from_array()` (e.g. no `key`, or a `key` prefix that disagrees with `provider_id`); its `level` is not `settlement`; its `provider_id` is not a registered provider; or its `settlement.name` is empty. **No FIAS id is required from any provider** — neither the canonical `Location_Record::fias_id` (optional, §4.3) nor DaData's native `raw.fias_id` (a valid foreign DaData record may have an empty one and a derived key). A row without the canonical id is scoped by country + name (K14) | «Выберите населённый пункт из подсказок.» |
| The same place twice (equal keys) | «Этот населённый пункт уже в списке.» |
| Mode off | the list is kept and not validated |
| **r7.1:** a row whose country is **not selectable now** (outside `options` — e.g. it left `location.countries`, or the DaData key was removed, K8) but is still in «Доставка в страны» (`in_scope`) | **not an error, not flagged, published** — the K8 fallback still needs it (§3.1 `in_scope`, §3.4) |
| **r7.1:** a row whose country is **outside «Доставка в страны»** (`in_scope`) | **not an error** — kept and flagged (§3.1), not published (§3.4); the picker is the gate for new rows, and a crafted row in such a country is simply not published |

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
   пос. Коммунарка. Подсказки адреса ищутся в том виде, как понимает выбранный
   населённый пункт сервис подсказок: например, при «Москва» в списке Зеленоград
   сам не добавится — если вы возите и туда, добавьте его отдельной строкой.
   То же для посёлка, который покупатель должен видеть отдельно.
   ┌──────────────────────────┬─────────────────────────────┬──────────────────────┐
   │ Страна                   │ Населённый пункт            │                      │
   ├──────────────────────────┼─────────────────────────────┼──────────────────────┤
   │ Беларусь                 │ г Минск                     │ Изменить   Удалить   │
   │   страна не входит в     │                             │                      │
   │   доставку — на оформле- │                             │                      │
   │   нии не показывается    │                             │                      │
   │ Казахстан                │ г Алматы                    │ Изменить   Удалить   │
   │ Россия                   │ г Москва                    │ Изменить   Удалить   │
   │ Россия                   │ г Химки, Московская обл     │ Изменить   Удалить   │
   └──────────────────────────┴─────────────────────────────┴──────────────────────┘
   ┌─ Добавить город ───────────────────────────────────────────────────────────────┐
   │ Страна            [ Россия                ▾ ]   ← no select with one country   │
   │ Населённый пункт  [ начните вводить…        ]   ← searches that country only   │
   │                                              [ Отмена ]  [ Сохранить ]         │
   └────────────────────────────────────────────────────────────────────────────────┘
                                                       [ Добавить город ]

   [ ] Скрывать поле «Населённый пункт»
       Работает, когда в списке один город: покупателю не нужно его выбирать, поля
       на оформлении заказа не будет, город подставится сам. Поле «Адрес» остаётся —
       без адреса заказ не доставить.
```

**r7 copy (operator, K16).** With places per country, three plugin strings say «per country»: the mode description gains «Для
страны, в которой у вас нет населённых пунктов, поле работает как обычно.»; the hide-field description becomes «Работает для страны, в которой в
списке один населённый пункт: …» (rest unchanged); its `disabled_reason` becomes «Сейчас в каждой стране списка несколько населённых пунктов.
Переключатель работает для страны, где в списке один.» The flag text is the operator's own. The table mock above shows the
r7 shape only; the shipped strings of PR #41 stay where r7 does not touch them.

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
| **r7:** mode on, every row flagged (no listed country is in «Доставка в страны») | «Ни одна страна из списка не входит в доставку — режим не действует.» (operator, K16) |

**r7 — what is published (K15).** `City_Mode` publishes only the rows whose record's country is in WooCommerce «Доставка в страны» (the same
`in_scope` predicate as the flag, one helper in `City_List`); `is_in_force()` needs at least one such row. `hide_field` is published as the **stored
switch**, no longer pre-multiplied by «exactly one place»: the framework applies it to each country that has exactly one place (§4.2). The
`woodev_location_default_locality` hook answers **the single place of the country it is asked about** (the framework passes that country, §4.2), not
«the only record of the whole list». **r7.1:** the callback is registered with **`accepted_args = 2`** (`add_filter( FILTER_DEFAULT_LOCALITY, …, 10,
2 )` in `City_Mode::register()`) — WordPress's default of one would deliver only `$current`, and the country would never reach it.

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

**r6 — operator, 06.11.2026 (after his rig look; replaces the r5.1 rows «several / one shown / one hidden»).** The settlement is never a search field in
city mode, and the **State row is hidden whenever places are listed** — its value comes from the place, so it is never asked. Where the place maps to no
region option (State still empty after the City is filled) the State row stays visible, so the shopper can choose it and the order is not stranded.

| Case | Classic (`location-cascade.js`, `location-select-modes.js`) | Block (`src/checkout-blocks`) |
|---|---|---|
| **Several places** | The settlement `<input>` becomes a **plain `<select>`** (renderer `closed-select`): a blank «Select…» option, then exactly the listed places, each posting its `value`. **Never select2** — no search box — and **no settlement `/suggest`**, whatever the shop's field mode (the list is known up front). A pick calls `/select` as today and writes the place's region into State. State row hidden by class from the start | `locality-chooser.tsx` renders a **native `<select>`** of the listed places instead of the combobox; its label is the native City field's own label. The native City row and the State row are hidden by class; a pick calls `/select`, then writes City = `value` and State from the record's region (`mapping.ts::resolveNativeAddress()`). Zero `/suggest` |
| **One place, field shown** | The fixed default (§2) seeds the chain implicitly; the settlement is **plain text** (renderer `closed-text`): the `<input>` stays in the form as `type="hidden"` carrying the place's `value`, a `<strong class="woodev-city-mode__place">` shows its name — the way WooCommerce shows the country when a shop sells to one. State row hidden once it holds the region | The chooser is not mounted; `single-place.tsx` writes City/State of the place into the cart address store and renders the **place as text** (`.woodev-city-mode-place`, under the native City field's own label). The native City row and (once it holds the place's region) the State row are hidden by class |
| **One place, hidden** (`hideSettlement`) | As above, and the settlement row is hidden too (JS + CSS class `woodev-field--hidden`, the way `address_field=hide_for_pickup` hides a row — not `unset()`, so the value still posts) | As above, with **no text**: City and State rows hidden by class, the place carried by the address store |
| **Address pick** | Street/house/postcode filled as today; City/State are **not** rewritten from the address record (that backwards-fill would put «Пушкино» over «Черкизово»). Suggestions stay scoped to the chosen place (§4.3) | Same |
| **«Адрес»** | Untouched: still shown and required wherever the shop's own «Поля» policy shows it; city mode never hides or relaxes it | Same |

The `hideSettlement` flag acts on exactly one place; with several places the select stays whatever the flag says (the plugin disables the toggle then — plugin card, not this repo).

**#1634 (operator, 06.11.2026, merged in PR #1637)** already replaced the classic «Several places» cell: the closed list is **select2** (selectWoo, like
WooCommerce's «Страна»), still with **no request** — the options are the listed places; the search box shows only above five places
(`minimumResultsForSearch`); without selectWoo on the page the plain `<select>` stays. The block cell is unchanged (native `<select>`, #1634: «Блочное
оформление не затрагивается»).

**r7 — everything above is per the customer's country (K15).** «Customer's country» is the delivery section's country (§4.1): the live
`billing_country` / `shipping_country` field on the clients, and on the server `Location_Service::customer_shipping_country()` as it is today.
**Accepted gap (coordinator, 06.11.2026, after the repeat critic):** a returning customer whose saved billing country differs from the saved
shipping country, delivering to billing, gets the page-load config (`defaultLocality`, the fixed policy) for the saved *shipping* country until the
first `update_order_review`; this is rare and self-heals, because the classic checkout fires `update_order_review` on page load and the posted
country is current from then on, and a first-render country (`checkout_view_country()`) would need its own surface boundary for the classic page,
AJAX, REST and the Store API, which the case does not justify. The config keys change as follows (replaces the four rows of the key table above):

| Key | r7 value |
|---|---|
| `allowed[country]` | as before — but only for countries that have a **published** place (§3.4), so a flagged row never reaches the checkout |
| `hideSettlement` | the merchant's **stored switch** as published; a client applies it only where `allowed[country]` has exactly one place (classic `syncHiddenSettlementRows()` already checks `places.length`; block mounts `single-place.tsx` only for one place) |
| `allowCustomSettlement` | **the store's own value, no longer clamped** — a client treats a country with `allowed[country]` as closed (no free text: classic `closed-*` renderers compute `allowCustomSettlement && ! allowedPlaces( country )`); a country without places keeps the store rule, on the server too (`guard_custom_settlement()` is unchanged). **r7.1 — the classic pickup City guard** (`holdsGuardedSettlement()`, #961) holds the settlement when the country has a closed list **whatever the shop's ordinary field mode**, OR under the existing rule (`ajax-select2` and custom off): today its `'ajax-select2' === settlementAxisMode()` test alone would let a pickup point's City overwrite a listed place in a `typeahead` shop, although `resolveModeRenderer()` renders that country as `closed-*` |
| `levels[country].region` | `false` **only for a country with `allowed[country]`**; every other country keeps its D15 answer |
| `defaultLocality` | the fixed default is the single place **of the customer's country**: `woodev_location_default_locality` is applied as `apply_filters( tag, null, $country )`, `$country = customer_shipping_country()`; a country with several or no places gets no filtered default and the store's own «Локация» policy |

Two rows join the behaviour table:

| Case | Classic | Block |
|---|---|---|
| **No place in the customer's country** | The settlement field is whatever the shop's «Локация» settings make it (typeahead / select2 search / related list), the region row is asked as usual, address suggestions are scoped as usual — the listed places of other countries are neither offered nor used. At placement nothing requires a listed place: the #531 guard applies the store's own rule exactly as without city mode, and no city-mode rule exists on the server (K10) | The ordinary combobox and State row (`allowedPlaces( config, country ) === null` → `closedChoice` false in `locality-chooser.tsx`) |
| **The customer changes country** | The settlement widget is re-resolved for the new country (closed list ↔ text ↔ the shop's own mode) — today `reconcileAfterCheckoutUpdate()` re-attaches only when the input element itself was replaced, so the country-change path must re-attach when `resolveModeRenderer()` would now pick another renderer or another country's list; the existing `clearCountryScope()` / `/forget` clears the old country's chain; a single place of the new country is written from `allowed[country][0]` on the client (the server's `defaultLocality` was computed for the render-time country only); the region row follows `syncHiddenSettlementRows()` | Already per country (`allowedPlaces( config, country )`, `singlePlace()`); the switch must leave no stale City/State of the previous country's single place (Jest) |

`Location_Service`'s list-membership reads (§4.4) are per country too: `settlement_choices_narrow_this_read()`, `is_outside_settlement_choices()`,
`saved_city_for_checkout()`, the region skip in `get_customer_chain()` and `names_listed_settlement()` look only at the places of the country concerned
(the record's own country, or `customer_shipping_country()`); `Checkout_Handler::is_rejected_city_prefill()` uses the section's own country. A
returning customer whose saved city is in a country without places keeps it, street and postcode included.

### 4.3 Address suggestions inside the chosen place — one provider-agnostic rule (K11′, K12–K14)

**The rule.** The plugin never decides where addresses are searched. The record the merchant picked from the list is the chain's settlement record
after the buyer's pick, and it is passed **as-is** to the active provider's own scoping (`Location_Scope` parent → the provider's `suggest()` for the
address level). No «federal city → region only» rule, no type-based special case, no country branch, nothing in the range plugin. What r5 adds is in the
**framework's DaData provider**, the one place that turns a parent record into a DaData `locations` filter (card F2): a parent that carries its own FIAS
id is scoped by that id.

**Where the FIAS id lives (K12).** `Location_Record` gains one optional, provider-agnostic field **`fias_id`** (accessor `fias_id(): string`, `''`
when absent): *the FIAS GUID of the record's own locality* — for a settlement-level record the deepest of DaData's `settlement_fias_id` /
`city_fias_id`, which is exactly the id the CDEK adapter already matches CDEK's `fias_guid` against (READ `class-cdek-location-adapter.php:594-629`,
`settlement_fias_id ?: city_fias_id`). It is a top-level field, not `raw`, because the framework never inspects a foreign provider's `raw` (spec D12,
READ `class-location-record.php` class docblock) and `native_parent_ids()` reads `raw` only for a DaData record. `from_array()` normalises it — trimmed,
lower-cased, kept only when it is a UUID **and** `country` is `RU`; anything else (an OSM `relation:…` id, a non-RU record, a malformed value) is stored
as `''`, never an exception, so a provider cannot break a record by passing a non-FIAS id. `to_array()` carries it, so it survives the option row, the
session slot and `/select`. Coordinates need no new field: `lat`/`lon` already exist and the CDEK record already fills them (READ
`class-cdek-location-provider.php:850-851` `record_from_compact()`, `:378-379` `enrich_selected_record()`). The DaData mapper does not fill `fias_id` in
F2 — its own `raw` ids already scope a Russian DaData record exactly (branch 1 below).

**Two different things share the name «fias id» — keep them apart.** The **canonical** `Location_Record::fias_id` is the new optional top-level field
above; F2 adds it, E1 fills it for CDEK, the DaData mapper leaves it `''`. DaData's **native** `raw.fias_id` (and `raw.city_fias_id`,
`raw.settlement_fias_id`, …) is DaData's own payload inside `raw`, already there today: READ `record_from_dadata_fields()` (`class-dadata-provider.php:1396-1444`)
puts an ordinary Москва pick's GUID into the key (`dadata:0c5b2444-…`) and `raw.fias_id`, with no canonical field — confirmed by the r5 critic's probe.
Branch 1 reads only the native ids; branch 2 reads only the canonical one; **nothing validates or requires either** (§3.2).

**The filter, in order** (`build_locations_constraint()` + `suggest()`; `restrict_value: true` whenever a parent narrows, as today):

| # | Parent record | `locations` sent | Narrowing | Name post-filter (`foreign_locality_parent()`) |
|---|---|---|---|---|
| 1 | **Russian** (`country = RU`) DaData record with native ids in its own `raw` (unchanged for RU) | `country_iso_code` + every non-empty `region_fias_id`, `area_fias_id`, `city_fias_id`, `settlement_fias_id` | `EXACT` | no |
| 2 | **New:** any other record with a non-empty canonical `fias_id()` (RU only, by construction) | `[ { country_iso_code: RU, city_fias_id: <id> } ]`; **when that answer is empty**, exactly one more request with `[ { country_iso_code: RU, settlement_fias_id: <id> } ]` | `EXACT` | **no** — the id pins the place; the name check would only lose rows |
| 3 | **Every non-RU record of any provider** (a DaData `UZ`/`KZ` record **with** OSM/GeoNames ids included — **changed in r5.1**), and a Russian record with a locality name but no id (a CDEK row without `fias_guid`, a row saved before E1, a DaData record without native ids) | `[ { country_iso_code, city: <own name> } ]`, then the existing `settlement: <own name>` retry; **own name** = `nested_settlement.name` when the record has one, else `settlement.name` | `DEGRADED` | yes — `foreign_locality_parent()` returns the parent for every branch-3 record, DaData's included |
| 4 | Region-only / nothing (unchanged) | `region: <name>` / country only | `DEGRADED` / `NONE` | no |

Branch 3 for non-RU DaData records is a **framework-wide** change, not only city mode: `build_locations_constraint()` serves every checkout. A DaData shop
whose customer picks Ташкент now gets `city: Ташкент` + country instead of `city_fias_id: relation:2216724` + country; the same 13.08.2026 measurement
(READ `build_locations_constraint()` comment) gave 3 suggestions for both shapes, so no loss is expected, and the homonym post-filter now also guards
those records. F2 re-records that pair live (§7).

**Why city then settlement, in two requests.** One GUID is either a city or a settlement in FIAS, and the record does not say which (CDEK's row has no
level). DaData's `locations` is a list whose entries combine as OR, but the existing name path measured that a two-entry list stops DaData cutting the
locality out of every label (READ `class-dadata-provider.php` `suggest()` comment, 01.11.2026); branch 2 reuses that measured two-request shape. The
second request is sent only after an empty first answer, so a city place costs one request per keystroke and a settlement place two — the same cost the
name path already has. Keys: `city_fias_id` and `settlement_fias_id` are the keys the framework already sends from DaData's own records (READ
`native_parent_ids()`, `:1196`) and the r4 measurement used them (rows 2, 5); DaData's address-suggestion docs list `locations` as «Ограничение по
родителю» (Context7, `/websites/dadata_ru_api`, `suggest/address`) — the per-key table was not returned there, so the keys are evidenced by the live
code and the r4 calls, not by the docs page. **UNVERIFIED:** whether `city_fias_id` **alone** scopes a federal city (Москва's GUID is also its
`region_fias_id`; r4 row 1 sent both). F2 records live calls: Москва GUID as `city_fias_id` alone — «Москва ул Тверская 7» found (positive) and
«Панфиловский проспект 10», a Зеленоград street, **not** found (negative); Черкизово's GUID — empty as city, found as settlement. **There is no
region-only step** (r5.1): `region_fias_id` alone would widen Москва to its whole region, which holds Зеленоград (§4.3 measured records). If the Москва
positive call returns nothing, the only allowed third shape is the **pair** `[ { country_iso_code: RU, region_fias_id: <id>, city_fias_id: <id> } ]` —
the same GUID in both keys, the shape r4 row 1 measured (Зеленоград excluded) — sent after the same empty-answer rule; F2 then re-records the Москва
positive and the Зеленоград negative for that pair, and its retry-count test changes from «exactly one more request» to «at most two more». The pair keeps
the city constraint, so it cannot widen any place; it applies to every GUID in the same order, not as a type branch.

**What happens when a listed entry has no FIAS id** (K14): it takes branch 3 — country + its own locality name, `NARROWING_DEGRADED`, the homonym
post-filter on. It is never an error: the settings save accepts it (§3.2), the checkout choice works, only the scoping is by name. This covers **every
non-RU record of every provider**: DaData's OSM/GeoNames ids (`relation:…`) are never used for scoping and never enter the canonical `fias_id`.

What the list scopes, per provider:

| Provider (registry) | The list's records | Address suggestions are scoped by | Source (READ) |
|---|---|---|---|
| **DaData** (framework, the default and the `address` fallback) | DaData settlement records, full `raw` | **RU:** branch 1, an exact `locations` filter from the record's native ids — whatever DaData files under those ids is inside the place, nothing else. **Outside RU:** branch 3, country + own locality name (r5.1, K14) | `fw: class-dadata-provider.php:1145,1188-1210` (`native_parent_ids()`), `:1106` |
| **CDEK** (`plugin: woocommerce-edostavka`, registered through `woodev_location_providers`) | CDEK city records: `key` `cdek:<code>`, `settlement.name`, `region.name`, `district`, `lat/lon`, `raw = { code }`, `ancestors = [ cdek:r<region_code> ]`; **after E1 also `fias_id`** (Russian rows whose dictionary row has a `fias_guid`) | CDEK serves only the `region` and `settlement` levels, so the **choice** of the place is CDEK's; ADDRESS suggestions still come from DaData (the `address` level falls through the D15 chain to the bundled DaData provider when the key is set; with no key there are none, K8). With E1: **branch 2**, by the place's FIAS id. Without E1 (today's CDEK release, non-RU rows, rows saved before E1): **branch 3**, by settlement name | `plugin: includes/location/class-cdek-location-provider.php:226-228` (levels), `:826-861` (`record_from_compact()`), `:931-951` (`compact_city()` — drops `fias_guid`); `fw: class-location-service.php:3652` (`resolve_provider_for_level()`), `class-dadata-provider.php:1153-1170` (name fallback), `class-location-scope.php:270` (`components_from_record()`) |
| **Others** | none other is registered by any Woodev plugin read on this machine (Yandex, Russian Post, Ozon: no `woodev_location_providers` hook) | branch 2 if the provider fills `fias_id`, else branch 3/4 — whatever its records carry; the plugin neither knows nor adjusts it | `fw: interface-location-provider.php` |

Consequences, stated once: with DaData, listing «Москва» searches addresses under DaData's Moscow ids; with CDEK after E1, under Moscow's FIAS id; with
CDEK before E1 or outside Russia, by the name. If the merchant also delivers to Зеленоград (or any place the provider treats as separate), he lists it as
another row. The plugin makes no attempt to know that one place «contains» another.

**Measured, DaData `/suggest/address`, 05.11.2026** (16 calls; address level `street`→`house`, `restrict_value: true`, `country_iso_code: RU`; only counts and
city/settlement names kept). Records: «Москва» = `city_fias_id` = `region_fias_id` (`0c5b2444-…`); «Зеленоград» = its **own** `city_fias_id`
(`ec44c0ee-…`) under the same `region_fias_id`; «Коммунарка» = `city_fias_id` Москва + own `settlement_fias_id` (`5a8a22a4-…`).

| # | Query | Filter | Count | What came back |
|---|---|---|---|---|
| 1 | Зеленоград корп 1 | Москва record (region + city = Москва) | 10 | all `city = Москва` («Ленинградское шоссе, к 1» …) — **no Зеленоград row** |
| 2 | Зеленоград корп 1 | Зеленоград record (region + own city id) | 10 | all `city = Зеленоград` («к 1», «к 100» …) |
| 3 | Коммунарка ул Лазурная | Москва record | 10 | `city = Москва`, `settlement = Коммунарка` (and Дудкино) — **Новая Москва is inside** |
| 4 | Коммунарка ул Лазурная | Зеленоград record | 0 | — |
| 5 | Коммунарка ул Лазурная | Коммунарка record (+ `settlement_fias_id`) | 10 | only `settlement = Коммунарка` |
| 6 | Москва ул Тверская 7 | Москва record | 10 | `city = Москва` (ул Тверская, д 7 …) |
| 7 | Москва ул Тверская 7 | Зеленоград record | 0 | — (expected) |
| 8 | Панфиловский проспект 10 (a Зеленоград street) | Москва record | **0** | Зеленоград is not in Москва's filter |
| 9 | Панфиловский проспект 10 | Зеленоград record | 10 | `city = Зеленоград` (Панфиловский пр-кт) |
| 10 | Коммунарка ул Лазурная | CDEK-style name filter `city: Москва` | 10 | `city = Москва`, Коммунарка (as row 3) |
| 11 | Панфиловский проспект 10 | name filter `city: Москва` | 0 | Зеленоград is not under the name «Москва» either |
| 12 | Панфиловский проспект 10 | name filter `city: Зеленоград` | 10 | `city = Зеленоград` |

(Three more calls found the records themselves: `/suggest/address` at `city`→`settlement` bounds for «Москва», «Зеленоград», «Коммунарка».) So **DaData's own
Moscow includes Новая Москва (Коммунарка) but not Зеленоград** — K11 (r3) was half wrong, and K11′ makes the question moot: whatever the provider decides
is what the merchant gets, and a Зеленоград address needs a Зеленоград row. The fixtures behind this table are re-recorded in C15.

This is the operator's mechanism: a buyer who uses the suggestions cannot pick an address outside the place as the provider scopes it. A buyer who
ignores them and types a far-away street gets no range rate, because the distance grid refuses it (K10).

### 4.4 Returning customer with a saved other city

While `allowed` is present, a chain settlement record whose key is not listed, and WooCommerce's saved city text (`customer_saved_city()`, READ
`class-location-service.php:1690`) that names no listed `value`, are **ignored for the checkout view** — one added rule in
`is_customer_record_stale()` (1160) and a guard in the saved-city reads. With several places the field shows the placeholder; with one place the fixed
default takes over (the saved-city early return in `get_customer_record()` would otherwise block it — READ 300-469). The street and postcode prefilled
from that rejected city are cleared in the checkout view (classic `handle_checkout_get_value()`, 725; block: the cart address store). The customer's
stored My Account address is never edited. This is presentation, not validation: nothing is rejected at submit.

Related fix — **every consumer of the parent-city projection** must accept a nested record's own name, because §4.2 makes the native City post
«Черкизово» (the `nested_settlement`), not the parent «Пушкино». The consumers (READ, all callers searched; r5 critic probe:
`settlement_record_value()` = «Пушкино», `Checkout_Handler::record_names_city( record, "Черкизово" )` = false):

| Consumer | Where (READ) | Today | Fix |
|---|---|---|---|
| `Location_Service::record_names_city()` (staleness rule c) | `class-location-service.php:1323`, caller `:1512` | compares with `settlement()` only → a Store API request drops the nested record as stale (r2 critic) | accept the own-locality name |
| `Checkout_Handler::settlement_record_value()` | `checkout/class-checkout-handler.php:2421` | returns `settlement.name` = Пушкино | returns the own-locality name (`nested_settlement.name` when present, else `settlement.name`) — the same `value` §4.2 posts |
| `Checkout_Handler::guard_custom_settlement()` | `:2304`, called from `handle_checkout_process()` `:2234` | compares the posted City with `settlement_record_value()` → in `ajax-select2` with custom localities off, a posted «Черкизово» is **refused** at placement | follows from the projection fix |
| `Checkout_Handler::city_names_record()` | `:1875` (callers `:1449`, `:1819`, `:1854`) | compares through `settlement_record_value()` | follows from the projection fix; accepts the parent name too, so a saved «Пушкино» still matches |
| `Checkout_Handler::record_names_city()` used by `remember_checkout_location()` | `:4090`, caller `:4061` | refuses the record → the order **loses** its settlement meta | accept the own-locality name |
| `Order_Editor::location_record_names_city()` | `admin/orders/class-order-editor.php:1421`, caller `:1405` | same comparison on the order-edit screen | same rule (the screen itself is unchanged, §1) |

One shared rule, not six: F2 adds one helper (on `Location_Record` — e.g. `locality_names()` already lists settlement + nested names, READ
`class-location-record.php:712`) and every consumer above calls it; none keeps its own copy of the comparison.

## 5. Licence, key and outage

| State | Checkout |
|---|---|
| Mode off | the shop's own «Локация» settings — nothing changed |
| **Licence not in force** (K7) | the filters are not published → the shop's own settings; the range method itself is hidden by the framework's licence gate (v2 spec §2.6); notice §3.4 |
| DaData **down, key present** (K8) | the choice is served from the config, so it works unchanged; `/select` stores the listed record (the DaData provider's `enrich_selected_record()` is the identity — READ by the r2 critic); only address suggestions fail |
| DaData **key removed** (K8) | the location layer is inactive (`Location_Service::is_active()`, READ 274-298): no location config, no cascade. **Classic:** the plugin turns the delivery City into a WooCommerce `select` of the listed `value`s through `woocommerce_checkout_fields` — only while the layer is inactive, so it never meets the cascade. **Block:** the native City stays a text field — a K5 gap (§8). Notice §3.4 |
| List empty (crafted) | treated as off; notice §3.4 |

**r7:** the key-removed classic `select` is per country too — `City_Select_Fallback` publishes the listed `value`s **keyed by country**, the server
renders the City as a `select` only when the delivery country at render has places, and `assets/js/city-select.js` swaps select ↔ text when the buyer
changes the country (it already swaps by section). A country without places keeps the text City. **r7.1:** the script is loaded in a
**billing-only shop too** — `City_Select_Fallback::enqueue_script()` drops its `wc_ship_to_billing_address_only()` exclusion, which dated from the
script's only job being the «Доставить по другому адресу» swap; with no checkbox on the page the billing City is the delivery City. Without that, a
billing-only shop shipping to RU and KZ would keep the server-rendered RU select after the buyer switches to KZ. When both countries have lists the
existing select's options are replaced.

No outbound call is added anywhere: the choice is local, `/select` already exists, suggestions are DaData's existing call.

## 6. Interaction with the range method and other carriers

- **Range method:** unchanged. It prices by distance (v2 spec §2.3); an address beyond its maximum distance gets no rate. With one place the chain is
  the item's DaData record, so a cart-page quote without a street takes the settlement point, precision `approximate`, with the v2 spec §6.2 notice.
- **Other Woodev carriers (СДЭК, Яндекс, Почта, Ozon):** they read the same checkout field and chain, so their buyers also choose only listed places —
  what the «Важно» box tells the merchant. Their pickup maps are not restricted (§9). **r7:** only in the countries that have listed places; a buyer
  in any other country chooses freely, so the box's «их покупатели тоже не смогут выбрать город вне вашего списка» is true per listed country (copy:
  operator, K16).
- **Non-Woodev methods:** not touched.
- **Older framework copies in other plugins:** irrelevant — the loader runs the highest copy (READ `class-framework-resolver.php`); `Range_Plugin`
  bundles ≥ 2.0.26.

## 7. Cards

`F` = framework, `C` = plugin (`kalbac/woocommerce-range-shipping`), `E` = `kalbac/woocommerce-edostavka`; F/C numbers continue the v2 spec's (F1,
C1–C13). Issues (Russian, on the boards, with «Приоритет») are created by the coordinator when the spec is approved. F2–F3 are part of **framework 2.0.26
itself** (draft release PR #1606); the plugin release bundles the matching `woodev/` tag. E1 is its own PR, parked until the next CDEK release.

| Card | Repo | Depends | Size | Critic |
|---|---|---|---|---|
| **F2** | framework (PHP) | — | M | Codex |
| **F3** | framework (classic + block clients) | F2 | M | Codex |
| **C14** | range plugin (settings, publication, fallback) | F2 (interface) | M | Codex |
| **C15** | range plugin (fixtures, rig, docs) | C14, F3 | M | operator reads the report |
| **E1** | edostavka (`fias_id` + coordinates in the CDEK record) | F2 (the record field) | S | Codex |

**F2 — framework PHP.** Scope: `woodev_location_settlement_choices` (static-memoised) and `woodev_location_default_locality` filters (§2);
`build_location_block()` keys of §4.2 for checkout/cart configs only; the returning-customer rule + saved-city guard + view clearing (§4.4); the
nested-name fix in **every consumer of §4.4's table** (`Location_Service::record_names_city()`, `Checkout_Handler::settlement_record_value()` /
`guard_custom_settlement()` / `city_names_record()` / `record_names_city()` + `remember_checkout_location()`, `Order_Editor::location_record_names_city()`)
through one shared helper; **`Location_Record::fias_id`** (§4.3: canonical, optional, normalised, RU + UUID only, in `to_array()`); **branch 2** of the
DaData filter (`build_locations_constraint()`, the `suggest()` empty-answer retry, `foreign_locality_parent()` returning null for a FIAS-pinned parent);
**branch 3 for every non-RU parent** (DaData's included) with the own-locality name; the live probe calls of §4.3 (Москва positive, Зеленоград negative,
Черкизово, Ташкент by name) recorded in the PR body. Acceptance (unit tests, each named after its case):

- config with 1 and 3 places and the hidden flag carries `allowed`, `hideSettlement`, `allowCustomSettlement: false`, `levels.region: false`; the My
  Account config has none of them; a nested record's `value` is its own name («Черкизово»);
- `Location_Record::from_array()` keeps `fias_id` for an RU UUID (lower-cased), stores `''` for a non-RU record, an OSM `relation:…` id and a malformed
  string, and never throws for them; `to_array()` → `from_array()` round-trips it; a payload without the key builds as before (existing record tests green);
- a **Russian** DaData parent sends branch 1 exactly as today (snapshot of the request body unchanged); a CDEK-style parent with `fias_id` sends
  `[ { country_iso_code: RU, city_fias_id } ]`, reports `NARROWING_EXACT`, and on an empty fake answer sends exactly one more request with
  `settlement_fias_id` (and never a `region_fias_id`-only body); no name post-filter drops its rows; a CDEK-style parent **without** `fias_id` sends the
  name filter and reports `NARROWING_DEGRADED`;
- **non-RU, every provider:** a DaData `UZ` Ташкент record whose `raw` holds `city_fias_id: relation:2216724` sends
  `[ { country_iso_code: UZ, city: Ташкент } ]` — **no `*_fias_id` key** — reports `NARROWING_DEGRADED` and post-filters by locality; a CDEK `KZ`
  Алматы record sends the same shape for its name; a nested record sends its `nested_settlement` name; existing DaData tests that asserted OSM-id bodies
  for foreign records are updated to the name body, not deleted; no branch reads the record's type or a federal-city list (K11′);
- **nested name at classic placement:** with the location field mode `ajax-select2` and custom localities **off**, a checkout POST with City «Черкизово»
  and the рп Черкизово chain record (parent «Пушкино») passes `handle_checkout_process()` without the `guard_custom_settlement()` error, and after
  `remember_checkout_location()` the order's location meta holds that record (not dropped); `city_names_record()` matches both «Черкизово» and
  «Пушкино»; `Location_Service::record_names_city()` keeps the record on a Store API request; the order editor accepts «Черкизово»;
- saved Tula ignored for the checkout view; the fixed default comes from the filter without touching the stored «Локация» option;
- `composer test` (with `rm -f .phpunit.result.cache` first) and `composer phpcs` green.

**F3 — framework clients.** Scope: classic cascade (closed local list, no settlement `/suggest`, hidden row, no backwards-fill, region from the pick) and
block chooser (closed list, native City/State write incl. the `resolveNativeAddress()` branch, hidden single writes the address store, rejected-city
clearing); the block hidden-field probe. Acceptance: Jest cases for each row of the §4.2 behaviour table on both clients (several / one shown / one hidden
/ address pick does not rewrite City); a network assertion that no settlement-level `/suggest` is sent while `allowed` is present; the block hidden-field
probe result (posts or not) written into the PR body and the chosen write path matching it; a worker rig check of A1, A3 and A4 on classic and block with
the placed order's City/State/street; `npm run test:js` green. Bundles are built in the primary checkout only (project fact 5).

**C14 — plugin settings, publication, fallback.** Scope: `City_Mode_Settings` (sub-tab «Доставка в городе», three options, boolean `show_if`, validation
§3.2, texts §3.3), `City_Mode` (licence-gated publication of the two F2 filters), notices §3.4, the key-removed classic `select` fallback (§5). The
settings shell can start at once; publication codes against F2's filter signatures. Acceptance (unit tests): save rejects an empty list, a duplicate key,
and each invalid payload of §3.2 by name — a row JSON without `key`, a `region`-level record, a record with `provider_id: "nope"` (unregistered), a
settlement record with an empty `settlement.name`; save **succeeds** for real mapper output with no canonical `fias_id` — a RU DaData Москва record built
by `record_from_dadata_fields()` (key `dadata:0c5b2444-…`, GUID only in `raw.fias_id`), a non-RU DaData record (Ташкент, OSM-id `raw`, and one with a
derived key and empty `raw.fias_id`), a CDEK RU record with and without `fias_id`, and a CDEK `KZ` record (K14); a saved row round-trips save → reload → the same
`Location_Record::to_array()`, `raw` and `fias_id` included (never through `City_Limit::encode()`); the filters are not hooked when the switch is off, the
list is empty or `License_Gate::is_in_force()` is false; each §3.4 notice renders under its condition and none is stored; with the location layer inactive
the classic delivery City is a `select` of the listed `value`s; the plugin's phpcs and test suite green; the coordinator's Playwright look at the tab at
1440 and 1280 before «done».

**C15 — fixtures, rig, docs.** Scope: recorded DaData fixtures — the §4.3 measured table (Москва filter with Зеленоград and Коммунарка queries, Зеленоград
filter, the CDEK-style name filter), **the branch-2 FIAS filter** (Москва GUID as `city_fias_id`: «Москва ул Тверская 7» positive, «Панфиловский
проспект 10» negative — and the same pair for the `region_fias_id` + `city_fias_id` shape if F2 had to adopt it; Черкизово GUID empty as city, answered
as `settlement_fias_id`), **the non-RU name filter** (Ташкент by `city` + `UZ`, Алматы by `city` + `KZ`; no OSM-id fixture), Химки, рп Черкизово; rig acceptance §8 on classic + block with a report and screenshots on disk; docs (`docs/CURRENT-STATE.md`,
product page text, materials package); Russian catalogue. Acceptance: every §8 row reported pass/fail per surface with the placed order's fields; fixtures
replay offline in the plugin's test suite; no key or real phone in any screenshot.

**E1 — `woocommerce-edostavka`: keep `fias_guid` and coordinates in the CDEK record (K12).** A separate card and PR in the CDEK repo; it is **not**
released in this wave (parked PR; ships with the next CDEK release). Scope:

- `compact_city()` (READ `class-cdek-location-provider.php:931-951`) keeps the row's `fias_guid` as a new compact key `f` — lower-cased, only for an RU row
  whose value is a UUID, else `''`; the `CompactCity` phpstan type gains it. A region list cached before E1 has no `f`: it reads as `''` (`?? ''`) until
  the cache refreshes — no cache-key bump, no migration;
- `record_from_compact()` emits `fias_id => f` beside the `lat`/`lon` it already emits; the class docblock line «FIAS is not used» is corrected;
- `enrich_selected_record()` / `picked_city_coordinates()` fill `fias_id` from the same one-row dictionary lookup that already fills the coordinates (the
  row is fetched anyway), stored in the same per-city transient; a record whose coordinates are missing still gets its `fias_id` (today the method returns
  early when there is no latitude);
- unscoped suggestions (`suggest_unscoped()`, `/location/suggest/cities`) take `f` from a warm region list, exactly as they take coordinates; whether that
  endpoint returns `fias_guid` itself is UNVERIFIED and not relied on;
- the bundled framework is bumped to a copy with `Location_Record::fias_id` (2.0.26). On an older framework the extra key is ignored by `from_array()`
  (READ: it builds only known keys), so E1 never fatals; scoping then stays by name.

E1 acceptance (unit tests): a dictionary row with `fias_guid` gives a record whose `fias_id()` equals it lower-cased and whose `lat`/`lon` are the row's
(4 decimals); a `KZ` row and a row without `fias_guid` give `fias_id() === ''`; a pre-E1 cached compact row without `f` builds a record without error;
`enrich_selected_record()` adds `fias_id` when coordinates are absent; the adapter's existing FIAS lookup tests stay green; the plugin's phpcs and test
suite green.

**r7 cards (K15, issue #1642)** — four independent work packages with disjoint files, then the plugin package; files, tests and sizes in
[the r7 plan](../plans/2026-11-06-1642-city-mode-country.md):

| Package | Repo / files | Depends | Size |
|---|---|---|---|
| **WP-A** framework PHP — per-country config keys, per-country narrowing, the default-locality filter's `$country` | `checkout/class-checkout-config.php`, `location/class-location-service.php`, `checkout/class-checkout-handler.php` + unit tests | — | M |
| **WP-B** framework classic JS — re-attach on country change, per-country custom-settlement clamp, single place of a newly chosen country, the pickup City guard independent of the shop's mode (r7.1) | `location-cascade.js` (+ `location-select-modes.js` only if the clamp needs it) + Jest | WP-A's wire (stated in §4.2, coded against it) | M |
| **WP-C** framework block TS — country switch, per-country `levels.region` | `src/checkout-blocks/*` + Jest | same | S |
| **WP-D** framework admin — `location-country` records-table column (+ resolved `default` / `names`, r7.1), per-row picker country, sorted rows, the per-country `count` condition with column declarations on both PHP save paths (r7.1) | `settings-api/class-control.php`, `settings-api/class-setting.php`, `settings-api/abstract-class-settings.php`, `settings-page/class-records-table.php`, `settings-page/class-field-schema.php`, `settings-page/class-composite-settings-handler.php`, `src/components/records-table.tsx`, `src/components/validate.js`, ADR-008 + tests | — | M |
| **WP-E** plugin — the «Страна» column, publication by shipping country, per-country hide/disabled/clear, notices, per-country fallback (also in billing-only shops, r7.1), the default-locality hook with `accepted_args = 2` (r7.1) | `woocommerce-range-shipping` `includes/city-mode/*`, `assets/js/city-select.js` + tests | WP-A, WP-D (signatures) | M |

**Waves.** W-A: F2 ∥ C14. W-B: F3 ∥ E1. W-C: C15. **r7:** WP-A ∥ WP-B ∥ WP-C ∥ WP-D, then WP-E, then the §8 rows A13–A18 inside C15's rig run. Inside the v2 spec's W5 slot; C13 (e2e, materials) waits for C15 because the product text describes this
tab. E1 is not on the range plugin's release path: city mode works with today's CDEK release by name (branch 3) and becomes FIAS-exact when E1 ships.

## 8. Acceptance on the rig

WordPress rig with DaData, the range method (maximum distance set), a flat rate and, where available, СДЭК; WC current and current−1. **Classic and
block for every row**; a row passes when the placed order's City/State/street are right, not just the chooser label.

| # | Scenario | Pass when |
|---|---|---|
| A1 | 1 place hidden (Москва) | No City/region row in the delivery section; «Адрес» shown and required; address suggestions only inside Москва as DaData scopes it — Коммунарка streets are offered, a **Зеленоград address is not suggested** (K11′, §4.3 rows 1, 3, 8); the order says Москва |
| A1b | 2 places (Москва, Зеленоград) | Both filters apply per pick: after picking Зеленоград, «Панфиловский проспект 10» is suggested, «Тверская 7» is not; after picking Москва the reverse (§4.3 rows 8-9, 6-7) |
| A1c | CDEK as the settlement provider, 1 place (Москва) with a DaData key — run **twice**: today's CDEK release (no `fias_id`) and an E1 build | The place is CDEK's record. Without E1: suggestions by the name «Москва» (branch 3) — Коммунарка offered, Зеленоград street not. With E1: the stored row carries Moscow's `fias_id` and the `/suggest` request sends `city_fias_id` (branch 2) — the same outcome. Without a DaData key no suggestions and the typed address stays free (§4.3 CDEK row) |
| A1d | CDEK, nested place (Черкизово) with E1 | The `/suggest` network log shows `city_fias_id` answered empty, then `settlement_fias_id` answered; only Черкизово addresses; City posts «Черкизово» |
| A1e | A place outside Russia (a CDEK `KZ` city, e.g. Алматы, and a DaData `KZ`/`UZ` settlement) | Both rows have no canonical `fias_id` and **both** are scoped by country + own locality name (branch 3): the `/suggest` network log shows `city: <name>` + `country_iso_code` and **no `*_fias_id` key** for either, the DaData row included (r5.1); both give addresses only in that city; saving the list accepts both (K14) |
| A2 | 1 place, flag off | City row visible, prefilled and locked to Москва |
| A3 | 3 places (Москва, Химки, пос. Коммунарка) | The field offers exactly these three; no settlement `/suggest` request in the network log; after picking Химки, suggestions only in Химки; picking an address does not change City |
| A4 | Nested settlement (рп Черкизово listed) | City posts «Черкизово»; suggestions only in Черкизово; the chain survives placement (not dropped as stale); after saving and reloading the settings the `locations` filter is still the exact `settlement_fias_id` |
| A5 | Range boundary (K10) | An address inside the place but beyond the maximum distance gets no range rate; an address inside the distance gets the exact price; the cart quote with one hidden place shows the approximate price and notice |
| A6 | Returning customer, saved city Тула, 3 places / 1 place hidden | 3 places: placeholder, Tula street and postcode not prefilled. 1 place: Москва prefilled, no Tula street. My Account address unchanged |
| A7 | `billing_only` shop, and a separate shipping address with «Доставить по другому адресу» toggled | The closed choice follows the delivery section on every toggle; the payer's billing city outside the list is accepted |
| A8 | Licence not in force, then renewed | Ordinary City field, any city; range method hidden; the §3.4 notice; renewal restores the mode on the next request |
| A9 | DaData key removed; DaData unreachable with the key present | Key removed — classic: City is a `select` of the listed places, no suggestions, notice; block: text City (K5 gap, recorded). Unreachable — the choice works, only suggestions fail |
| A10 | Settings: save with the mode on and an empty list; with a duplicate; with a crafted row that is a `region`-level record (posted by editing the request). Then save a list of real picks without a canonical `fias_id` — DaData Москва, DaData Ташкент, CDEK Алматы; toggle the mode on | The first three: blocking errors of §3.2, nothing saved. The real picks save without error. The two dependent fields appear on toggle and are saved |
| A11 | Mode switched off after use; My Account address edit with the mode on | Off: the shop's own «Локация» behaviour, no leftovers. My Account: the unrestricted picker as today |
| A12 | Another carrier active (СДЭК) | Its buyers also pick only listed places; its pickup map is not restricted (§9) |
| **A13** | r7 · **Россия, several places** (Москва, Химки, пос. Коммунарка) in a shop that ships to RU, KZ, UZ | Admin: «Страна» before «Населённый пункт», «Россия» chosen, the three places found and listed under «Россия». Checkout, country RU: select2 of exactly the three, no settlement `/suggest`, State hidden; the order says the picked place |
| **A14** | r7 · **a second country with its own place** (Казахстан → Алматы; then a second KZ place) | Admin: with «Казахстан» chosen «Алматы» is found (with «Россия» it is not); the table lists Казахстан before Россия. Checkout KZ, one place: «Алматы» as text, State hidden, the `/suggest` log shows `country_iso_code: KZ` + `city: Алматы` (branch 3); with two KZ places a select2 list. RU → KZ → RU switches re-render each time with no City/State left from the other country; the placed order is right for each |
| **A15** | r7 · **a country without places** (Узбекистан) | Checkout UZ: the shop's ordinary settlement field with suggestions and a region row, Ташкент chosen by search, the order **places** (no city-mode refusal); the #531 guard applies only the store's own rule. A returning customer with a saved UZ city keeps it, street included |
| **A16** | r7 · **a single-country shop** («Доставка в страны» = Россия only) | Admin: no country dropdown — «Россия» is shown as text and used for the search; checkout as A1–A3 |
| **A17** | r7 · **a flagged row** — Казахстан removed from «Доставка в страны» after A14 | Admin: the KZ rows stay, each with «страна не входит в доставку — на оформлении не показывается»; saving the page keeps them. Checkout: KZ cannot be chosen as the shipping country when the shop restricts shipping countries (WooCommerce); when selling/shipping countries are «all» the billing country stays selectable (a billing-only shop can still pick KZ), and the flagged KZ rows are simply not offered there; RU is unchanged; with **every** listed country removed, the mode reads as off (§3.4 r7 notice) and the checkout is ordinary. «Скрывать поле»: inactive / active per the `min` rule (K16), live as rows are added and removed |
| **A18** | Card #1642 «Проверка» · prices of other carriers in city mode | With city mode on and СДЭК active, the СДЭК rate for a listed place comes from a **live** carrier request (request log / debug log), not from a cached quote of another city |

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
| Mapped counterparts for non-DaData providers (`map_allowed_item()`, result type, budget, cache, cron) | The list holds the active provider's own records and passes each on as-is (K11′); the cross-provider steps are `build_locations_constraint()`'s FIAS branch (r5, K13 — the record's own id, no mapping call) and its existing name fallback |
| r4's «§4.3 needs no framework code, no card» | **Superseded by r5** (K12–K13): the operator asked for FIAS scoping, which needs the `Location_Record::fias_id` field and one DaData branch (in F2) plus the CDEK change (E1). K11′ itself is unchanged — no type, federal-city or country rule was added |
| Provisional P1–P5 | P3 decided by the operator (K11′, 05.11.2026 — no federal-city rule); the rest vanished with the boundary |
| **r3's K11** and its `native_parent_ids()` federal-city rule | Replaced by K11′: measured, DaData's own Moscow already includes Новая Москва and excludes Зеленоград, and the operator wants the provider's scoping used unmodified. The `record_names_city()` nested fix **stays** — it serves nested settlements (рп Черкизово), not federal cities (§4.4) |
| Cards F4–F6, C16 | Merged into F2/F3/C14/C15 |

Still applied from the reviews: the record is stored whole (r1 finding 2), a nested settlement posts its own name and survives the staleness rule
(r1/r2 finding 3), the clients' real behaviour per surface (r1 finding 4), release in 2.0.26 (r1 finding 10), boolean `show_if` (r1 finding 11).

## 10. Decided by the operator

Items 1–7 were provisional, reversible choices (r5, r7) made so nothing waited. **The operator confirmed all seven exactly as written on
06.11.2026 (s220: «всё по рекомендациям»)**; they are binding now and recorded in §0 as K16 and K17. The numbering is kept, because the plan and
earlier reports cite it.

1. **No migration and no notice for CDEK rows saved before E1** (K17). They stay name-scoped (branch 3) until the merchant re-picks the row; city mode
   is new in 2.0.0, so such rows exist only on shops that used it between the range release and the next CDEK release.
2. **E1 ships with the next regular CDEK release**, not as its own hotfix (K17). Until then CDEK shops get the r4 name scoping, measured right for
   Москва / Зеленоград / Коммунарка (§4.3 rows 10-12).
3. **«Скрывать поле» counted per country = `min`** (K16). The switch is inactive while **every** listed country has more than one place (RU 3 +
   KZ 1 → active, and it hides the KZ field only). It is one value of the `aggregate` key (§3.1); the save-time clearing follows it.
4. **The country choices include countries with no address suggestions** (K16; AM, AZ, KG, TJ, TM on the measured rig — §3.1 «Coverage»), because
   `location.countries` includes them. A place there works as a choice; the street is typed freely. No extra help text.
5. **Per-country wording** of three plugin strings (§3.3 r7 copy) and of the «Важно» box (§6) ships as written (K16).
6. **A notice when every row is flagged** (§3.4) ships as written (K16).
7. **The block's «several places» stays a native `<select>`** (K16) — #1634 changed the classic checkout only and says so; K15's «several → select2
   (#1634)» reads as «the #1634 list», not as select2 in Blocks.

## Revision log

| Rev | Date | Change |
|---|---|---|
| r1 | 05.11.2026 | First design (Sonnet): a store-wide server-side city restriction |
| r2 | 05.11.2026 | Codex review findings 1-11 resolved; operator decisions recorded (57b3f4fa) |
| r3 | 05.11.2026 | Rewritten to the operator's redirect: the list is granularity, not a boundary (K10); cards F2, F3, C14, C15; rig A1-A12 (f9c4c9f3) |
| r4 | 05.11.2026 | K11 («federal city includes its districts») replaced by K11′ — the provider decides what a place covers; DaData measured, 16 calls (48c9cb11) |
| r5 | 06.11.2026 | Operator's late inputs of 05.11.2026: K12 (a record keeps its FIAS id; CDEK stops dropping `fias_guid` — card **E1**, separate edostavka PR), K13 (DaData scoped by that id, `city_fias_id` then `settlement_fias_id`), K14 (FIAS is Russia-only; no id → country + name). New `Location_Record::fias_id` field and DaData branch 2 in F2 (§4.3). **Contradicts r4 in one place:** r4 said §4.3 needs no framework code and no card — superseded (§9). K6-K10 and K11′ unchanged. §7 rewritten with per-card acceptance and E1; §8 gains A1c (twice), A1d, A1e; §10 added |
| r5.1 | 06.11.2026 | Codex (gpt-6.1-sol) r5 review, four must-fix findings applied. (1) The canonical `Location_Record::fias_id` is kept apart from DaData's native `raw.fias_id`; §3.2 requires no FIAS id from any provider and names the exact invalid payloads; C14/A10 add successful saves of real RU and non-RU mapper output. (2) §4.4 lists every consumer of the parent-city projection (`Checkout_Handler::settlement_record_value()` / `guard_custom_settlement()` / `city_names_record()` / `record_names_city()` + `remember_checkout_location()`, `Location_Service::record_names_city()`, `Order_Editor::location_record_names_city()`); F2 fixes them through one helper, with a PHP case posting «Черкизово» under `ajax-select2` + custom localities off. (3) Coordinator decision: the operator's input wins — outside RU every provider, DaData included, is scoped by country + own locality name (OSM ids no longer used; a framework-wide change); §4.3, K14, F2 tests, C15 fixtures and A1e updated, r5's §10 item 1 dropped. (4) The region-only third retry is deleted; the only allowed third shape is the `region_fias_id` + `city_fias_id` pair, with a Москва positive and a Зеленоград negative probe in F2/C15 |
| r6 | 06.11.2026 | Operator's decision after his rig look (card F5 #1627, «operator, 06.11.2026»): §4.2 behaviour table rewritten — one place = plain text (not a locked field), several places = a plain dropdown of exactly the listed places (no select2 search box, no settlement `/suggest`), the State row hidden in both (kept visible only while a filled City leaves it empty); block checkout: native `<select>` instead of the combobox, native City row replaced by it |
| r7 | 06.11.2026 | Operator's decision K15 (#1642, variant 2): a country per place. §0 K15; §3.1 the derived «Страна» column (`location-country`, `options` = shipping ∩ `location.countries`, `in_scope` = shipping), per-row picker country, sorted rows, the flag, the per-country `count` condition, the measured coverage and what s219 saw; §3.2 a foreign-country row is not an error; §3.3 table mock + provisional copy; §3.4 publication by shipping country, `hide_field` as stored, default locality per country; §4.2 #1634 recorded, per-country config keys, two behaviour rows (no place in the country, country switch), per-country §4.4 reads; §5 per-country key-removed fallback; §6; §7 packages WP-A–WP-E; §8 A13–A18; §10 items 3–7. Plan: `plans/2026-11-06-1642-city-mode-country.md` |
| r7.1 | 06.11.2026 | The r7 critic's seven findings (Codex) folded in, and the operator's confirmation of §10. (1) §3.1: the PHP count evaluator gets each records-table's column declarations from both save paths (`Woodev_Abstract_Settings::filter_visible_values()` and the composite handler); (2) §3.4: the default-locality callback is registered with `accepted_args = 2`; (3) §5: the key-removed fallback script also loads in a billing-only shop; (4) §4.2: the classic pickup City guard holds a listed place whatever the shop's field mode; (5) §4.2: ~~the server's per-country reads use the new `checkout_view_country()`~~ — dropped 06.11.2026 (coordinator, repeat critic): they use `customer_shipping_country()`, the saved-billing ≠ saved-shipping first-render case is an accepted, self-healing gap; (6) §3.1: the `location-country` column gains framework-resolved `default` (base country) and `names` (options ∪ stored countries); (7) §3.2: «not selectable now» (outside `options`) is neither flagged nor unpublished — only «outside shipping» (`in_scope`) is. Operator, 06.11.2026 (s220, «всё по рекомендациям»): §10 items 1–7 confirmed as written → K16, K17; §3.1, §3.3, §3.4, §6, §8 A17 and §10 now cite the decisions instead of «provisional». Plan r2: files per package, tests per finding |

## Related

- [2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) — the v2 spec this extends; D1–D16, §2.3 distance pricing, §2.6 licence gate, §6 checkout.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the customer chain, staleness gate and default locality reused in §2 and §4.4.
- [2026-08-24-popular-settlements-design.md](2026-08-24-popular-settlements-design.md) — the popular-list wire shape the closed list reuses (§4.2).
- [2026-08-12-location-provider-design.md](2026-08-12-location-provider-design.md) — provider capabilities and the DaData `locations` filter behind §4.3.
- [../adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — why a toggle's `show_if` value is boolean and the hide toggle cannot depend on a row count (§3.1).
- [../plans/2026-11-06-1642-city-mode-country.md](../plans/2026-11-06-1642-city-mode-country.md) — the r7 implementation plan (work packages WP-A–WP-E).
