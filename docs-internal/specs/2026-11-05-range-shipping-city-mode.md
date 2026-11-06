# «Доставка в пределах города» (range shipping 2.0.0) — design

**Date:** 2026-11-05. **Status:** draft r6 (06.11.2026) — history in the [revision log](#revision-log). r3 was **rewritten after the operator's
redirect of 05.11.2026** (§0, binding): the list of places is the **granularity of the checkout's settlement choice and of the active provider's address
suggestions**, not a delivery boundary. The delivery boundary stays where it already is — the range method's own distance grid. r1/r2 designed a
store-wide server-side restriction; §9 lists what was removed and why. **r4** replaced K11 («a federal city includes its districts») with the operator's
**K11′**: the plugin hands the listed record to the provider and decides nothing about what a city contains. **r5** applies the operator's late inputs of
05.11.2026 (K12–K14): a record carries its **FIAS id** wherever its provider knows one (the CDEK provider stops dropping `fias_guid` — card E1, a separate
`woocommerce-edostavka` PR), and DaData address suggestions for such a record are scoped **by that id** (city OR settlement); FIAS is Russia-only, so
outside Russia the scope is country + the record's own locality name, for every provider. **r5.1** applies the r5 critic's four must-fix findings (revision
log). **r6** applies the operator's decision after his rig look of 06.11.2026 (§4.2 behaviour table, card F5 #1627): one place is plain text, several
places are a plain dropdown, the State row is hidden in both. Nothing in this revision blocks on the operator; §10 lists provisional choices.
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

### 3.2 Validation on Save (server)

| Rule | Message |
|---|---|
| Mode on, list empty | «Добавьте хотя бы один населённый пункт — иначе покупателю будет нечего выбрать.» |
| A row is not a valid settlement record: its JSON is refused by `Location_Record::from_array()` (e.g. no `key`, or a `key` prefix that disagrees with `provider_id`); its `level` is not `settlement`; its `provider_id` is not a registered provider; or its `settlement.name` is empty. **No FIAS id is required from any provider** — neither the canonical `Location_Record::fias_id` (optional, §4.3) nor DaData's native `raw.fias_id` (a valid foreign DaData record may have an empty one and a derived key). A row without the canonical id is scoped by country + name (K14) | «Выберите населённый пункт из подсказок.» |
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
   пос. Коммунарка. Подсказки адреса ищутся в том виде, как понимает выбранный
   населённый пункт сервис подсказок: например, при «Москва» в списке Зеленоград
   сам не добавится — если вы возите и туда, добавьте его отдельной строкой.
   То же для посёлка, который покупатель должен видеть отдельно.
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

1. **No migration and no notice for CDEK rows saved before E1.** They stay name-scoped (branch 3) until the merchant re-picks the row; city mode is new in
   2.0.0, so such rows exist only on shops that used it between the range release and the next CDEK release. Reversal: C14 re-enriches rows without
   `fias_id` on the settings render (one bounded CDEK lookup per row) or shows a debug-mode hint.
2. **E1 ships with the next regular CDEK release**, not as its own hotfix. Until then CDEK shops get the r4 name scoping, measured right for Москва /
   Зеленоград / Коммунарка (§4.3 rows 10-12). Reversal: a CDEK patch release right after the range release.

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

## Related

- [2026-11-05-range-shipping-v2-design.md](2026-11-05-range-shipping-v2-design.md) — the v2 spec this extends; D1–D16, §2.3 distance pricing, §2.6 licence gate, §6 checkout.
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the customer chain, staleness gate and default locality reused in §2 and §4.4.
- [2026-08-24-popular-settlements-design.md](2026-08-24-popular-settlements-design.md) — the popular-list wire shape the closed list reuses (§4.2).
- [2026-08-12-location-provider-design.md](2026-08-12-location-provider-design.md) — provider capabilities and the DaData `locations` filter behind §4.3.
- [../adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — why a toggle's `show_if` value is boolean and the hide toggle cannot depend on a row count (§3.1).
