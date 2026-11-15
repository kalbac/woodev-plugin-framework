# Marketplace admin UI — the pages look like the delivery plugins (#1776)

**Status:** decided — operator approved every item of #1776 on 15.11.2026; this spec is the design, cards C1–C3 implement it.
r2 folds in the r1 critic of PR #1777: the empty state without `emptyMessage`, the merge order and owners of the shared
files, C2's strings and stylesheet, the held store categories, and three smaller edits.
**Date:** 2026-11-15. **Target:** framework 2.1.x (next release after #1767), first consumer Ozon Seller 2.0.0.

**Evidence labels:** **READ** — code read for this spec; **SEEN** — rig screenshot of 15.11.2026 (1440 px, Ozon Seller
on the framework main); **UNVERIFIED** — reason given, the named card proves it. `fw:` = this repo at `af7cd170`;
`ozon:` = `kalbac/woocommerce-ozon-seller` `main` at `d26f9a8`.

## 0. What the operator decided (#1776, 15.11.2026)

The page of reference is the delivery plugins: the shared «Заказы доставки» page and the Woodev settings page. Settings of
the marketplace already use the framework settings renderer and look right; every other marketplace page was built apart and
follows no pattern. The eight items, binding:

1. One tab look on every page — the folder tabs of «Заказы доставки» and of the settings.
2. «Товары» and «Заказы» tables in a white card: the card header holds «Выберите действие» + «Применить» and the search;
   counts above the table; pagination at the bottom; aligned filters; a search box that does not cut its placeholder; a
   «Период» list on «Заказы» instead of two browser date fields.
3. Field labels in sentence case, never upper case.
4. The add/edit forms of «Сопоставление» and «Профиль» in the settings layout: label on the left, controls of normal width,
   groups as titled blocks, width/height/depth/weight on one row with units, a category picker that does not look broken,
   no form pushed right of an empty column.
5. Headings over cards (the account name on «Параметры», «Какие уведомления получать от Ozon») inside the card.
6. «Уведомления»: one short instruction instead of five repeats of «Где указать адрес…»; the account block like CDEK's
   connection block — state as a line, one main button, the other actions secondary, no four one-button cards.
7. «Инструменты»: buttons named by their action, each tool a card.
8. «Диагностика» in the same cards as the other pages.

Plus four small items (§5). Done when the coordinator has walked every Ozon screen on the rig with screenshots beside the
delivery screens, and the operator has looked at the result.

## 1. Where each change lives

Everything in items 1–8 is framework code; the delivery code is a reference and does not change.

| Screen | Renderer (READ) | Owner of the content |
|---|---|---|
| Товары (2 tabs), Заказы | `src/marketplace-admin/views/records/index.tsx` → `src/admin-records/**` (WooCommerce runtime `TableCard`) | framework definitions `woodev/marketplace/admin/pages/{products,orders}/class-*-definition.php` |
| Сопоставление (2 tabs + forms) | `src/marketplace-admin/views/mappings/**` | framework `woodev/marketplace/admin/pages/mappings/**` (messages, choices) |
| Инструменты | `src/marketplace-admin/views/tools/tools-view.tsx` | framework page; **tool titles, descriptions, button labels are Ozon's** (`ozon:includes/ozon/adapter/class-ozon-tools.php`) |
| Диагностика | `src/marketplace-admin/views/diagnostics/diagnostics-view.tsx` | framework (`woodev/marketplace/admin/diagnostics/**`); report sections partly the adapter's, unchanged |
| Настройки (5 tabs) | `src/settings-page/**` through `embedded-view.ts` | framework `woodev/marketplace/settings/**`; **the cabinet location string is Ozon's** (`ozon:…/class-ozon-push-protocol.php:50`), unchanged |

Ozon edits are limited to wording (§5, §6 ozon#58 list).

## 2. Building blocks — reused, shared, new

### 2.1 Reused as they are (READ)

| Need | Existing piece | Note |
|---|---|---|
| Table card, sort headers, pagination, header `actions` slot, `hasSearch`, footer `summary` | WooCommerce runtime `TableCard` via `getTableCard()` (`src/admin-records/wc-runtime.ts`) | The delivery page uses the same props (`fw:src/shipping-orders-page/app.tsx:2842-2880`). `actions`, `summary`, `hasSearch` exist in WC 7.0 (the oldest WooCommerce a plugin of ours declares); C1 adds them to `TableCardProps`. **`emptyMessage` does not exist in 7.0** (Q3 note in `wc-runtime.ts:9-10`, `states.tsx:4-5`), so it is never passed — see «Empty result» in §3.1. |
| Label-left field rows | `FieldRow` (`src/components/field-row.js`) inside a `.woodev-settings` root | The grid is `.woodev-settings .woodev-field` in `src/settings-page/style.scss:64`. |
| Titled block inside a card | the `.woodev-group` / `__title` / `__desc` markup (`src/components/_group.scss`) | Markup only. Do not import `GroupCard`: it pulls the settings REST client into the marketplace bundle. |
| White card | `@wordpress/components` `Card` + `CardBody` with class `woodev-settings__card`, as the settings app uses | |
| Folder tab look | `.woodev-tabs` rules in `src/components/_wp-recolor.scss` (TabPanel class names) | |
| Searchable multi-select | `SelectField` with `multi` (`src/components/select-field.js`) | Used as is: it has no per-option `disabled`, see §4.2. |

**The settings stylesheet is already on every marketplace page.** `Settings_Page_Registry::enqueue_bundle()` enqueues
`woodev-settings-page` (`style-index.css` + `wp-components`) on every wc-admin screen once a marketplace plugin places a
provider (`fw:woodev/settings-page/class-settings-page-registry.php:842-868`, READ). A marketplace plugin always does. So the
classes above style the mappings, tools and diagnostics views without a new stylesheet. C2 writes this dependency into
the wiki (§6; C2 is the wiki's only owner).

### 2.2 Delivery-only pieces and how they are shared

The bulk select, the counted scope links («Все (32) | Новые (28)») and the «Период» picker exist only inside
`src/shipping-orders-page/` (`BulkActionsBar` and `ScopeLinks` are private functions of `app.tsx`; `period-picker.tsx`
depends on that page's `filters.ts`). The wiki invariant B3 forbids importing from or changing that folder. So:

- **This wave** writes generic, prop-driven components in `src/components/` (new files only). They are modelled on the
  delivery code and copy its class-name anatomy and CSS rules, but never import it. All visible strings arrive as props, so
  the records shell keeps Rule 9 (PHP supplies every word).
- **Follow-up card (needs the operator's word, it lifts B3 for one card):** switch «Заказы доставки» onto these shared
  components and delete the private copies, so the two pages cannot drift. Filed by the coordinator after C1 merges.

New shared files (owned by C1):

| File | What |
|---|---|
| `src/components/bulk-action-select.tsx` | `SelectControl` (hidden label) + secondary «Применить» button in one bordered group; props `actions`, `value`, `onChange`, `onApply`, `disabledReason`, `labels`. Same anatomy as `.woodev-orders-bulk`. |
| `src/components/count-links.tsx` | WordPress-style counted row: `ul > li > a` with `aria-current`, the pipe separator drawn in CSS, zero counts hidden except the current entry. Props `entries[{value,label,count}]`, `current`, `onSelect`. |
| `src/components/period-picker/presets.ts` | Pure: `presetRange( preset, today ): {from,to}` for `today`, `yesterday`, `week`, `last_week`, `month`, `last_month`, `quarter`, `last_quarter`, `year`, `last_year` (week starts on Monday), and `matchPreset( range, today )`. |
| `src/components/period-picker/period-picker.tsx` | A `Dropdown` with the FilterPicker class names: «Всё время», the ten presets, «Произвольный период» → WooCommerce runtime `DateRange` + «Назад» / «Применить»; without `window.wc.components.DateRange`, two native date inputs (today's control). Value in and out is `{from?, to?}`; labels are props. |
| `src/components/_table-chrome.scss` | The delivery rules for the toolbar row, bulk group, scope links and period button, renamed to `woodev-table-*`, plus the 430px filter-picker correction ([gotcha](../gotchas/woocommerce-gives-its-filter-picker-a-fixed-430px.md)). |

### 2.3 One more shared piece (owned by C2)

`src/components/folder-tabs.tsx` — a controlled tab bar: `tabs[{id,label}]`, `active`, `onSelect`. It renders TabPanel's
own markup (`div.woodev-tabs > div.components-tab-panel__tabs[role=tablist] > button.components-tab-panel__tabs-item[role=tab]`,
`is-active`, `aria-selected`), so the settings page's folder look applies unchanged. Controlled because the records page
keeps the active table in the URL: an uncontrolled `TabPanel` would show the old tab after Back/Forward.

## 3. Screens

Every page: the root drops its inline `paddingRight: 20` (wc-admin already pads the page); content width = the wc-admin
content area, like «Заказы доставки».

### 3.1 Товары — «Товары сайта» (C1 + C2)

Today (SEEN `ozon-01`): button-style table switch, upper-case filter labels, a checkbox floating between selects, 13 bulk
buttons on three rows, the empty text in a bare box.

```text
 [Товары сайта] [Карточки на маркетплейсе]                         ← FolderTabs (C2)
 ───────────────────────────────────────────────────────────────
 [Связать по артикулу]                                             ← account-scope actions (secondary buttons)
 Аккаунт            Категория          ☐ Только с ошибками        ← filter row, sentence-case labels, bottom-aligned
 [woodev      ▾]    [Любая       ▾]
 Все (120) | Не связан (80) | На модерации (3) | Продаётся (37)   ← CountLinks from the «Состояние» facets
┌──────────────────────────────────────────────────────────────┐
│ Товары   [Выберите действие ▾][Применить]  [🔍 Поиск по названию, артикулу……] │ ← TableCard header `actions`
├──────────────────────────────────────────────────────────────┤
│ ☐ │ Товар │ Артикул │ Состояние │ Остаток │ Цена │ Склад │ … │ ⋯ │
│ … rows, expandable variations (unchanged) …                   │
├──────────────────────────────────────────────────────────────┤
│        ‹ 1 2 › …                        120 Товаров          │ ← TableCard pagination + `summary`
└──────────────────────────────────────────────────────────────┘
                                         Товаров на странице [20 ▾]
```

**Empty result (decided).** `TableCard`'s `emptyMessage` does not exist in WC 7.0, which hard-codes «No data to display»
there, so the shell keeps drawing its own empty state, now in a card frame: a new `EmptyCard` in
`src/admin-records/states.tsx` built from `@wordpress/components` `Card` / `CardHeader` / `CardBody` (what `TableCard` itself
is made of). The header carries the same title and the same `actions` node (bulk group + search) as the table, so a search
that found nothing can still be edited or cleared. The body is today's `EmptyState` with the PHP `empty` / `empty_filtered`
text. It is never a bare box instead of the card. C1 matches the header's DOM and spacing to `TableCard`'s on the rig
(WC 11.3). A failed read keeps today's `ErrorState` with «Повторить». The Q3 notes in `wc-runtime.ts` and `states.tsx` stay
true and need no change.

Rules:

- **Selection actions** (`scope: selection`) go to `BulkActionSelect`. «Применить» is disabled with nothing chosen or nothing
  selected. When the chosen action is blocked (account line or a selected row's lock), «Применить» is disabled and the reason
  from `blockedReason()` shows beside it — the logic in `actions/bulk-toolbar.tsx` stays, only the presentation changes.
  `flow.begin()` and the preview/confirm dialog are unchanged.
- **Account actions** (`scope: account`, e.g. «Связать по артикулу», «Обновить список») are secondary buttons above the filter
  row, the place of «Создать заказ» on the delivery page. Their blocked reason is written under them, as today.
- **Counts row.** A `select` filter may declare `'views' => true` (at most one per definition). It is not drawn in the filter
  row; `CountLinks` draws it: «Все» then every option with its facet count. «Все» writes what the select's «Любое» writes
  today, so the URL semantics do not change. **What «Все» counts:** the options need not partition the rows — a variable product
  with variations in two state groups is listed under both — so the provider may send the real number as a facet entry with the
  empty value (`''`, «the filter itself left out»); «Все» shows it. Without that entry «Все» is the list total while no view is
  chosen and the sum of the facet counts otherwise, which is exact only for options that partition the rows (orders' open /
  finished, remote cards' on the site or not). The products provider sends the entry: the list total while no state is chosen,
  one more `COUNT` otherwise. The
  provider returns `facets[filter id]`: one count per option with every other filter applied (the engine already carries
  facets: `Row_Normalizer::facets()`, `Records_Rest_Controller` line 420, READ). Declared on: site products `state`
  (`State_Groups`), orders `state` (В работе / Завершены), remote cards `on_site`.
- **Search** moves from the filter row into the card header, right of the bulk group, filling the rest of the row
  (`flex: 1 1 240px`), so the placeholder is never cut. Below 782 px the search goes on top, the bulk group under it (the
  delivery rule).
- **Labels**: `.woodev-records .components-base-control__label` → `text-transform: none; font-size: 13px; font-weight: 500`.
  The boolean filter sits in a 40 px box aligned with the selects' controls.
- **Footer**: `summary = [{ label: messages.summary_label, value: total }]`. The page-size select stays under the card (PHP's
  sizes; WooCommerce's own picker stays hidden).
- New PHP messages (Rule 9) through one helper `Woodev\Framework\Admin\Records\Standard_Messages` (`bulk()`, `views()`,
  `period()`), merged by each definition: `bulk_placeholder` «Выберите действие», `bulk_apply` «Применить», `bulk_label`
  «Массовые действия», `views_all` «Все», `summary_label` («Товаров» / «Карточек» / «Заказов»), period labels (§3.3).

Files (C1): `src/admin-records/{records-table.tsx,filters.tsx,styles.ts,messages.ts,types.ts,query.ts,states.tsx,wc-runtime.ts}`,
`src/admin-records/actions/{bulk-toolbar.tsx,styles.ts}`, new `src/admin-records/style.scss` (imported by `records-table.tsx`:
the records engine is bundled by the marketplace bundle only, and `Admin_Collector` already enqueues `style-index.css` when it
exists — `fw:woodev/marketplace/admin/class-admin-collector.php:328`), the new `src/components/*` of §2.2;
PHP `woodev/admin/records/{class-definition-parser.php,class-records-definition.php}` + new `class-standard-messages.php`,
`woodev/marketplace/admin/pages/products/{class-site-products-definition.php,class-site-products-reader.php,class-site-products-provider.php,class-remote-cards-definition.php,class-remote-cards-query.php,class-remote-cards-provider.php}`,
`woodev/marketplace/admin/pages/orders/{class-site-orders-definition.php,class-site-orders-reader.php,class-site-orders-provider.php}`.
The tab bar (C2) is the only change in `views/records/index.tsx`.

### 3.2 Товары — «Карточки на маркетплейсе» (C1 + C2)

Same frame as §3.1. Above the filters: «Обновить список», «Связать по артикулу» (account actions). Filter row: «Показать»
(near-match report). Counts row: «Все | Нет на сайте | Уже на сайте» (`on_site` facets, default «Нет на сайте» stays the
current entry). Card header: `BulkActionSelect` with «Импортировать» (only when the adapter imports) + search. The
`summary` paragraph (last read, waiting) stays above the card, under the counts.

### 3.3 Заказы (C1)

Today (SEEN `ozon-03`): upper-case two-line label «СОСТОЯНИЕ ОТПРАВЛЕНИЯ», two browser `dd.mm.yyyy` fields.

```text
 Аккаунт          Статус заказа       Период
 [woodev    ▾]    [Любой       ▾]     [Всё время        ▾]        ← PeriodPicker for the `date_range` filter
 Все (42) | В работе (30) | Завершены (12)                         ← CountLinks from `state`
┌──────────────────────────────────────────────────────────────┐
│ Заказы                          [🔍 Поиск по отправлению или ID заказа……]   │ ← no bulk actions declared → search only
├──────────────────────────────────────────────────────────────┤
│ Заказ │ Отправление │ Статус │ Дата │ Покупатель │ Сумма │ Этикетки │ ⋯ │
├──────────────────────────────────────────────────────────────┤
│        ‹ 1 2 ›                              42 Заказов       │
└──────────────────────────────────────────────────────────────┘
```

Every `date_range` filter renders as `PeriodPicker` (only orders' `date` declares one). The URL stays `date=from~to`
(`query.ts` codec unchanged, no PHP query change): a preset writes its dates counted from the site's day — the `date_range`
filter carries `today` (`wp_date( 'Y-m-d' )`, validated by `Definition_Parser`), which is the day the server reads `from~to`
in; without it the browser's local day; the button
shows the preset's name while the dates still match it today, else «01.11.2026 — 15.11.2026», empty = «Всё время».
Accepted limitation: a bookmarked «Сегодня» is a fixed day the next morning. Period messages: `period_label` «Период»,
`period_all` «Всё время», the ten preset labels (the delivery page's own wording: «Сегодня», «Вчера», «С начала недели»,
«Прошлая неделя», «С начала месяца», «Прошлый месяц», «С начала квартала», «Прошлый квартал», «С начала года», «Прошлый
год»), `period_custom` «Произвольный период», `period_back` «Назад», `period_apply` «Применить период» (not the bare
«Применить»: the bulk button carries that name).

### 3.4 Сопоставление — lists (C2)

Today (SEEN `ozon-04`, `ozon-06`): underline tabs, a 320 px sidebar with a button and a bare list, «Выберите сопоставление
слева» in an empty right column.

```text
 [Сопоставления] [Профили]                                        ← FolderTabs, URL `tab=profiles`
┌──────────────────────────────────────────────────────────────┐
│ Сопоставления                          [Добавить сопоставление] │ ← TableCard title + header action (primary)
├──────────────────────────────────────────────────────────────┤
│ Название ▲    │ Категория маркетплейса   │ Профиль   │ Товаров │
│ Футболки      │ Одежда › Футболки        │ Основной  │    124  │
└──────────────────────────────────────────────────────────────┘
 Профили: Название │ Сопоставлений                      [Добавить профиль]
```

`TableCard` (through `getTableCard()`; C2 does not edit `wc-runtime.ts`, which C1 owns, and types the extra `actions`
prop with a local intersection type until it rebases onto C1) with all rows on one page (`rowsPerPage = max(rows, 1)`,
`showMenu = false`, client-side sort by name); the name
is a link button that opens the form. Empty (no `emptyMessage`, see §2.1): instead of the `TableCard`, a
`Card` / `CardHeader` (title + «Добавить…») / `CardBody` with `emptyMappings` / `emptyProfiles`. C2 writes this card itself
and does not import C1's `EmptyCard`, so the two cards stay independent. If WooCommerce draws a pagination bar for a single
page, hide it with a rule scoped to `.woodev-mappings-list` in C2's stylesheet (§4.4).

### 3.5 Сопоставление / Профиль — forms (C2), see §4

### 3.6 Инструменты (C3)

Today (SEEN `ozon-07b`): two headings with text and two identical «Проверить» buttons, no cards.

```text
 Аккаунт [woodev ▾]                                      ← only with 2+ accounts, FieldRow in its own row
┌ Пробный запуск миграции из версии 1 ─────────────────────────┐
│ Считает, что перенесла бы миграция…                          │
│ [Пробный запуск]                                             │ ← tool.button, secondary
│  ┌ preview ───────────────────────────────────────────────┐  │
│  │ summary                                                 │  │
│  │ [Пробный запуск] (primary = confirm_label)  [Отмена]    │  │
│  └─────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
┌ Миграция данных из версии 1 ──── … [Запустить миграцию] ─────┐
```

Each tool is a `Card.woodev-settings__card` with a `.woodev-group__title` heading. The first button shows `tool.button`
(already in the REST row, `ToolRow.button`, READ; the view shows `messages.previewButton` instead). It still opens the preview;
nothing runs before the confirm. `previewButton` is dropped from `Tools_Controller`'s messages. Busy/disabled notices stay
inside the card. Ozon's labels and descriptions change in ozon#58 (§6).

Files (C3): `src/marketplace-admin/views/tools/tools-view.tsx`,
`woodev/marketplace/admin/pages/tools/class-tools-controller.php`.

### 3.7 Диагностика (C3)

Today (SEEN `ozon-08`): text and a button straight on the page background, `widefat` tables of fixed width.

```text
┌ Логирование ─────────────────────────────────────────────────┐
│ Сейчас показано только состояние подключения. Чтобы увидеть… │
│ [Включить логирование]                                       │
└──────────────────────────────────────────────────────────────┘
┌ Лицензия ────────────────────────────────────────────────────┐
│ Состояние          недействительна — фоновые задачи остановлены │ ← dl.woodev-facts, label column 200px
└──────────────────────────────────────────────────────────────┘
┌ Аккаунт «woodev» ────────────────────────────────────────────┐
│ Client-Id          ***0680                                   │
│ Аккаунт            выключен   …                              │
└──────────────────────────────────────────────────────────────┘
┌ Отчёт для поддержки ─ (logging on) ──────────────────────────┐
│ hint · [Скопировать отчёт] · textarea (full card width)     │
└──────────────────────────────────────────────────────────────┘
```

One card per section, heading inside. Lines as `<dl class="woodev-facts">` (new class, in `src/settings-page/style.scss`:
the same 200 px label column as `.woodev-settings .woodev-field`, rows separated by rhythm, wrapped values). The logging
card's title is a new message `loggingHeading` «Логирование».

Files (C3): `src/marketplace-admin/views/diagnostics/diagnostics-view.tsx`,
`woodev/marketplace/admin/pages/diagnostics/class-diagnostics-payload.php` (the new message).

### 3.8 Настройки (C3)

**Подключение** (SEEN `ozon-09`): already the reference look. No change beyond §5 (new account switched on).

**Параметры** (SEEN `ozon-10`):

```text
 [Подключение] [Параметры] [Общие] [Уведомления] [Дополнительно]
┌──────────────────────────────────────────────────────────────┐
│ woodev  Client-Id: 4180680                                   │ ← heading INSIDE the card (was above it)
│ ┌ Остатки и склад ─────────────────────────────────────────┐ │
│ │ …                                                         │ │
│ ┌ Цены и наценка ──────────────────────────────────────────┐ │
│ │ Цены             [С сайта на Ozon               ▾]  ⓘ    │ │ ← short label, the explanation in the tooltip
│ │ …                                                         │ │
│ │ Минимальная цена, %  [80]                                 │ │
│ │ Пример по текущему правилу (…): цена 1000.00 RUB, …       │ │ ← moved here from the top of the card
│ └───────────────────────────────────────────────────────────┘ │
│ ┌ Заказы и карточки ─ … ┐                                     │
│ [Сохранить]                                                   │
└──────────────────────────────────────────────────────────────┘
```

- **Heading inside the card (item 5).** `StackedView` stops drawing the `h3`; it calls `renderSection( tab, sectionId,
  { title, subtitle } )` and `app.js` draws `<h3 class="woodev-settings__card-title">title <span muted>subtitle</span></h3>`
  as the card's first child. Only stacked tabs pass the third argument, so the delivery tabs render as before.
- **Price example** leaves the section description: `Settings_Group` gains an optional `footnote` (HTML, rendered by
  `GroupCard` after the fields and before the actions); `Accounts_Sections` puts `price_example_html()` there on the
  «Цены и наценка» group (`fw:woodev/marketplace/settings/class-accounts-sections.php:822,926`).
- **Price direction options** (`price_direction_options()`, line 1457): «С сайта на %s» / «С %s на сайт» / «Не синхронизировать
  цены»; the removed explanations join the field's tooltip.

**Общие**, **Дополнительно** (SEEN `ozon-11`, `ozon-13`): the reference look already; no change.

**Уведомления** (SEEN `ozon-12`):

```text
┌──────────────────────────────────────────────────────────────┐
│ Какие уведомления получать от Ozon                           │ ← heading inside the card
│ Включите уведомления, которые вам нужны: Ozon сообщит о них  │
│ сайту сразу, а выключенные сайт не станет принимать.         │
│ Где указать адрес: Личный кабинет Ozon → Настройки → …       │ ← ONCE (all five types share the location)
│ Новое отправление          [●]  ⓘ  (purpose in the tooltip)  │
│ Смена статуса отправления  [●]  ⓘ                             │
│ … five switches …                                            │
│ [Сохранить]                                                   │
└──────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────────┐
│ woodev  Client-Id: 4180680                                   │
│ Адрес              http://…/notifications/1/82f1…            │ ← dl.woodev-facts
│ Состояние          ещё не проверялось · последнее: пока не поступало │
│ Нужные события     о заказах (новые, отмены, …)              │
│ [Зарегистрировать адрес] [Проверить связь] [Обновить типы событий] [Возобновить уведомления] │
│ result line                                                  │
└──────────────────────────────────────────────────────────────┘
```

- `Notifications_Section::webhooks_html()` (READ, lines 214-247): the per-type `<ul>` goes; each switch keeps its own tooltip
  (the type's `purpose()`). The location sentence is built once per distinct `cabinet_location()`: one sentence when all
  types share it (Ozon), else one sentence per location naming its types. The «Зарегистрировать URL сама передаёт…» sentence
  stays, renamed to the new button text.
- `account_section()`: the four `<p><strong>` lines become one `dl.woodev-facts` (Состояние merges registration state and
  the last notification into one line); `->with_actions( …, Settings_Section::ACTIONS_ROW )` — a new optional layout flag
  serialised as `actions_layout: 'row'`. `SectionView` renders such actions with a new `ToolsRow` (in `tools-block.js`): one
  row of buttons, the first `primary`, the rest `secondary`, each tool's `desc` as its accessible description, the result line
  under the row. Default layout unchanged, so the delivery «Дополнительно» tool cards render as before.
- Button labels: «Зарегистрировать адрес», «Проверить связь», «Обновить типы событий», «Возобновить уведомления» (the tool
  names, now the buttons' descriptions, stay).

Files (C3): `src/settings-page/{stacked-view.tsx,app.js,section-view.js,tools-block.js,group-card.tsx,style.scss}`,
`woodev/settings-page/{class-settings-section.php,class-settings-group.php}`,
`woodev/marketplace/settings/{class-notifications-section.php,class-accounts-sections.php}`.

## 4. The Сопоставление and Профиль forms (C2)

### 4.1 Layout decision: the form replaces the list

Clicking a name or «Добавить…» swaps the list card for the form; «← Все сопоставления» / «← Все профили» swaps back. The
state is in the URL (`mapping=new|<id>`, `tab=profiles&profile=new|<id>`), so Back/Forward and a bookmark work.

Why not a form card under the list: the mapping form holds the attribute table, which needs the full content width, and the
profile form is about 20 rows long — under a list it starts below the fold and the merchant scrolls past the list on every
edit. Replacing the list is also WordPress's own pattern (list screen → edit screen). The
side-by-side layout of today is what pushed the form right of an empty column.

### 4.2 Mapping form

```text
 ← Все сопоставления
┌──────────────────────────────────────────────────────────────┐
│ Новое сопоставление | «Футболки»                              │ ← card title
│ Название                 [______________________]            │ ← FieldRow + TextControl (label hidden from vision)
│ ┌ Категории ───────────────────────────────────────────────┐ │ ← .woodev-group
│ │ Категории магазина     [Футболки, Майки        ▾]        │ │ ← SelectField multi (search + checklist popover)
│ │                         Сопоставление действует и на вложенные категории…  │
│ │ Категория маркетплейса [Начните вводить название……]      │ │ ← CategoryPicker, results in a bordered list
│ │ Профиль выгрузки       [— не выбран —          ▾]        │ │
│ └───────────────────────────────────────────────────────────┘ │
│ ┌ Характеристики ──────────────────────────────────────────┐ │
│ │ AttributeTable (full group width)                         │ │
│ └───────────────────────────────────────────────────────────┘ │
│ [Сохранить] [Копировать]                         [Удалить]   │
└──────────────────────────────────────────────────────────────┘
┌ Проверка товаров ─ CheckReport (existing) ───────────────────┐
```

- **Store categories.** `SelectField multi` (the settings dropdown with search) replaces the filter box + checkbox wall.
  The options are every shop category. A category held by another mapping stays in the list with today's suffix
  «Футболки — Уже входит в сопоставление: Летнее» (`shopCategoryTaken` + the holder's name, as `mapping-editor.tsx:232-234`
  builds it now). So a search for it still finds it and names the mapping to open. `SelectField` has no per-option
  `disabled` and stays unchanged (it is shared with delivery). The editor's `onChange` drops the ids of held categories
  before it updates the draft, so ticking one changes nothing; the server's own «one category, one mapping» check stays.
  The selected categories show as the trigger's summary; `shopCategoriesHelp` is the row's description.
- **Marketplace category.** `CategoryPicker` keeps its logic. Look: the input has the placeholder visible; results are a bordered
  list with hover rows (not a column of tertiary buttons); the chosen category shows as text with «Изменить» as a link
  button on the same line.

### 4.3 Profile form

```text
 ← Все профили
┌──────────────────────────────────────────────────────────────┐
│ Новый профиль | «Основной»                                    │
│ Название профиля          [______________________]           │
│ ┌ Откуда брать данные ─────────────────────────────────────┐ │
│ │ Артикул                 [Артикул (SKU) товара     ▾]      │ │
│ │ Штрихкод                [Не передавать штрихкод   ▾]      │ │
│ │   ↳ Ключ поля           [__________]  (only for «своё поле») │ │
│ │ Описание · Главное фото · Дополнительные фото …           │ │
│ └───────────────────────────────────────────────────────────┘ │
│ ┌ Запасные размеры и вес ──────────────────────────────────┐ │
│ │ Применяются к товарам, у которых размеры или вес не заполнены… │ │
│ │ Размеры (Ш × В × Г)   [   ] × [   ] × [   ]  [как в магазине (см) ▾] │ │
│ │ Вес                   [   ]                [как в магазине (кг) ▾] │ │
│ └───────────────────────────────────────────────────────────┘ │
│ ┌ Цена ────────────────────────────────────────────────────┐ │
│ │ Ставка НДС              [Не указывать          ▾]         │ │
│ │ Валюта цены             [Как в личном кабинете ▾]         │ │
│ │   ↳ Курс / Ключ поля    [____]  (by currency_kind)        │ │
│ │ Своё правило цены       [○]                               │ │
│ │   … six rule rows while on …                              │ │
│ └───────────────────────────────────────────────────────────┘ │
│ [Сохранить]                                       [Удалить]  │
└──────────────────────────────────────────────────────────────┘
```

- Labels come from the existing messages; the «Откуда брать …» labels shorten to the noun under the group title (the
  PHP-built source titles, `Profile_Choices`; the group heading says «Откуда брать данные»).
- **Size row**: three 80 px number inputs with `×` between them and the unit select, inside one `FieldRow` labelled
  `messages.fallbackDimensions` «Размеры (Ш × В × Г)»; each input keeps its own accessible label (Ширина/Высота/Глубина). The
  weight row: one input + the weight unit select. The default unit option names the shop's unit:
  «как в магазине (см)» (PHP reads `woocommerce_dimension_unit` / `woocommerce_weight_unit`).
- VAT moves into «Цена» (it is a price property); the «Валюта цены» select drops its duplicate legend.
- Delete is a tertiary destructive button at the right end of the action row; its two-step confirm stays.

### 4.4 New strings and the stylesheet (C2)

Every new visible string is a PHP message (Rule 9) in `View_Config`'s message lists
(`fw:woodev/marketplace/admin/pages/mappings/class-view-config.php:141-234`, READ). React never calls `__()` for these and
never reuses a key with a different meaning.

| Key | Text |
|---|---|
| `backToMappings` | «← Все сопоставления» |
| `backToProfiles` | «← Все профили» |
| `newMapping` | «Новое сопоставление» |
| `newProfile` | «Новый профиль» |
| `groupCategories` | «Категории» |
| `groupPrice` | «Цена» |
| `fallbackDimensions` | «Размеры (Ш × В × Г)» |
| `unitDefaultDimension` | «как в магазине (%s)» with the shop's dimension unit filled in by PHP, e.g. «как в магазине (см)» |
| `unitDefaultWeight` | the same for the weight unit, e.g. «как в магазине (кг)» |
| `columnProfile` | «Профиль» (list column) |

`unitDefault` («— как в магазине —») is replaced by the two unit-specific keys and removed. Retired keys (`chooseMapping`,
`chooseProfile`) are removed with the sidebar. The short source labels (Артикул, Штрихкод, Описание, Главное фото,
Дополнительные фото) are `Profile_Choices::titles()`.

C2's stylesheet is `src/marketplace-admin/views/mappings/style.scss`, imported by `mappings-view.tsx`. It lands in the
marketplace bundle's `style-index.css`, which `Admin_Collector` already enqueues. It holds the single-page pagination rule
(`.woodev-mappings-list`), the `CategoryPicker` result rows (border, `:hover`), and the size row (80 px inputs, the `×`
separators, the unit select inline).

Files (C2): `src/components/folder-tabs.tsx` (new), `src/marketplace-admin/views/records/index.tsx` (switcher only),
`src/marketplace-admin/views/mappings/{mappings-view.tsx,mapping-editor.tsx,profile-editor.tsx,category-picker.tsx,check-report.tsx,types.ts}`,
new `src/marketplace-admin/views/mappings/style.scss`,
PHP `woodev/marketplace/admin/pages/mappings/{class-view-config.php,class-profile-choices.php}`.

## 5. Small items of #1776

| Item | Where the code is (READ) | Change | Card |
|---|---|---|---|
| Price example at the end of «Цены и наценка» | `fw:…/class-accounts-sections.php:822,926` | §3.8 footnote | C3 |
| «С сайта на Ozon: …» cut off | `fw:…/class-accounts-sections.php:1457` | §3.8 short labels | C3 |
| A new account starts switched off | `fw:…/class-accounts-sections.php:650-656`: the add form's «Аккаунт включён» toggle defaults to `self::NEW_KEY !== $key`, i.e. off for the new-account form (since #1728, no stated reason; the rig's add form shows it off) | the default becomes `true`; a PHPUnit test pins it | C3 |
| «OZON Seller» in the menu vs «Ozon Seller» in the heading | `ozon:includes/class-plugin.php:172` | menu title «Ozon Seller» | ozon#58 |

The card filed all four under ozon#58; three are framework code, so they ride with C3. The coordinator updates ozon#58.

## 6. Implementation cards

**Run in parallel, merge in a fixed order: C1 → C3 → C2.** The source files of §3–§4 are disjoint. Exactly three
places are shared, and each has one rule:

| Shared place | Rule |
|---|---|
| Translation catalogue `woodev/languages/woodev-plugin-framework.pot`, `woodev-plugin-framework-ru_RU.po` (+ the compiled `.mo`) — every card adds PHP msgids, and `lint:i18n-sources` requires each in both files | Each card adds only its own entries. The first card merges as is. Each later card rebases on `main` before its merge, resolves the catalogue conflict by keeping both sides' entries, recompiles the `.mo`, and reruns `lint:i18n-sources`, `lint:i18n` and `lint:mo`. |
| `tests/js/marketplace-records-view.test.tsx` | C1 owns the bulk-toolbar assertions (today lines 75 and 84: `toolbar` → buttons become the select + «Применить»). C2 owns the switcher assertions (lines 118-131: `navigation` + `aria-current` become `tablist` / `tab` + `aria-selected`). C2 merges last and rebases onto C1's version of the file. |
| `docs-internal/wiki/marketplace-admin.md` | C2 only. It writes the «Admin UI as built (#1776)» subsection for all three cards after C1 and C3 have merged. |

Tier: **Sonnet 5.5** (`claude-sonnet-5-5`) each; a critic of another lineage on each PR. Every brief carries:

- the worker's own worktree path for Serena `activate_project` (PHP is read and edited through Serena; `tests/**` with
  Read/Edit);
- the delivery-untouched checks, both printing nothing:
  `git diff --name-only origin/main...HEAD -- src/shipping-orders-page woodev/shipping-method` and
  `git diff --name-only --diff-filter=M origin/main...HEAD -- src/components` (new files in `src/components/` are allowed;
  changes to existing ones are not);
- «bundles are built by the coordinator in the primary checkout, never committed by a worker»;
- the rig rule: probes and screenshots in the scratchpad only, no settings or branch change in the primary checkout; helper
  `ui-probe/lib.js`.

**Common acceptance** for every card: `npm run test:js` green (run from bash), `npm run lint:i18n-sources` green,
`npm run lint:docs` green, PHP unit tests of the touched classes green (`rm -f .phpunit.result.cache` first), phpcs green on
touched PHP. Then, after the coordinator's build: rig screenshots at 1440 px and 390 px of every screen the card touches,
next to the delivery reference shot. At 390 px the page has no horizontal scroll; a table may scroll inside its card, as
«Заказы доставки» does.

### C1 — Tables: card header, counts, period (L)

Scope: §2.2 shared components, §3.1, §3.2 (except the tab bar), §3.3.
Acceptance (beyond common):

- Jest: `presetRange()` / `matchPreset()` for every preset across a month, quarter and year boundary and a Sunday;
  `BulkActionSelect` (disabled states, blocked reason, account actions outside the select); `CountLinks` (sum, zero hidden,
  current kept, «Все» writes the «Любое» value); the records table renders the PHP empty text inside `EmptyCard`, whose
  header keeps the search with the typed text and keyboard focus stays in the search box when the table swaps to or from
  `EmptyCard` (focus restored after the remount; one jest assertion); the search sits in the table header (`records-table.test.tsx`,
  `admin-records-*.test.tsx` and the toolbar assertions of `marketplace-records-view.test.tsx` updated, not deleted).
- PHPUnit: `Definition_Parser` accepts `views` only on a `select` and only once; the products provider's `state` facets are the
  totals of the list filtered by each group, the groups are not a partition (a variable product with two states is in both) and
  the empty-value entry is the real number of products, with or without a chosen state; orders and remote cards facets.
- Rig: «Товары» both tabs and «Заказы» with ≥ 1 row and empty, a bulk action through preview to confirm, a period preset and a
  custom range, Back/Forward.

### C2 — Tabs and the mapping/profile forms (M)

Scope: §2.3, the tab bar of §3.1/§3.2, §3.4, §4.
Acceptance (beyond common):

- Jest: `FolderTabs` (aria, active, `onSelect` not called for the active tab); `marketplace-records-view.test.tsx`
  (switcher assertions only) — the switch still clears the left table's arguments; `marketplace-mappings-view.test.tsx`:
  list → form → back via the URL; save/copy/delete flows unchanged; a held category is listed with the holder's name and
  ticking it changes nothing; the size row's accessible labels.
- Rig: both mapping tabs (empty and with one mapping + one profile), both forms new and edit, the tab look of «Товары»,
  «Сопоставление» and «Настройки» in one screenshot row — identical folder tabs.
- Wiki (C2 is the only owner, written after C1 and C3 merged): `docs-internal/wiki/marketplace-admin.md` gets an «Admin UI
  as built (#1776)» subsection. It covers the reuse of the settings stylesheet (§2.1), the shared components (§2.2–2.3),
  C1's `views` flag and `EmptyCard`, and C3's `actions_layout` and group `footnote`.

### C3 — Settings, tools, diagnostics (M)

Scope: §3.6, §3.7, §3.8, §5 rows 1–3.
Acceptance (beyond common):

- Jest: `settings-page-stacked-view.test.js` (heading inside the card), `settings-page-tools-block.test.js` (`ToolsRow`: one
  primary, the rest secondary, result line; the default card layout unchanged), `settings-page-groups*.test.tsx` (footnote),
  `marketplace-tools-view.test.tsx` (the first button shows `tool.button`, still previews first).
- PHPUnit: `Notifications_Section` — one location sentence for shared locations, one per distinct location otherwise;
  `actions_layout` serialised only when set; `Accounts_Sections` — footnote on the price group, short option labels.
- Delivery parity: Woodev → Настройки of CDEK and Yandex screenshotted before and after, all tabs — identical.
- Rig: all five settings tabs, «Инструменты» with a preview open, «Диагностика» with logging off and on.

### Ozon edits (ozon#58, after C3 merges and a framework release)

- `includes/ozon/adapter/class-ozon-tools.php`: buttons «Пробный запуск» and «Запустить миграцию»; the descriptions drop
  «показывает кнопка «Проверить»» (new: «Перед запуском покажем итог прошлого пробного запуска.» / «…итог прошлого
  запуска.»); the two queued messages likewise.
- `includes/class-plugin.php:172`: menu title «Ozon Seller».

## 7. Not verified

- That `EmptyCard`'s header can match `TableCard`'s header DOM and spacing closely enough on WC 7.0 and 11.3. C1 compares on
  the rig (11.3); 7.0 is not on the rig.
- That WooCommerce's pagination bar collapses for a single page (§3.4) — C2 checks; the scoped rule is the fallback.
- That TabPanel's class names alone reproduce the folder look outside a real `TabPanel` (wp-components 6.9 may style the
  item through emotion as well). C2 compares screenshots; fallback: a real `TabPanel` remounted by `key` on URL change.
- The cost of the extra facet `COUNT` queries on a large shop (about seven per products read, two per orders read) — not
  measured; C1 measures one read on the rig and states the numbers in its PR.
- The `Remote_Cards_Query` storage shape for the `on_site` counts — not read.
- That a switched-off start for a new account (§5) is a defect and not a choice nobody recorded. #1728 gives no reason;
  the card lists it among the defects.
- ~~Whether `State_Groups` is a true partition of the products.~~ Resolved by C1: it is not (a variable product with variations in
  two groups is counted in both), hence the empty-value facet entry for «Все» in §3.1.
- The 390 px layout of every new screen — drawn here, not probed.

## Related

- [Marketplace module and PHP-driven React admin](../wiki/marketplace-admin.md) — Rule 9, the delivery-untouched invariant B3
- [Marketplace card importer spec](2026-11-14-marketplace-card-importer.md) — the remote-cards tab and «Импортировать»
- [Filter picker width gotcha](../gotchas/woocommerce-gives-its-filter-picker-a-fixed-430px.md)
- Framework card #1776; Ozon Seller card ozon#58
