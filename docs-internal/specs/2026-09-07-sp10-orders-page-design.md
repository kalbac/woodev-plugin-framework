# SP-10 «Заказы доставки» — page design

> Written in s125 (07.09.2026), in English per `DOCS-SCHEMA.md`. **The page SHAPE was settled by the
> operator in #694 (01.09.2026)** and is not reopened here: one landing page, a tab per carrier,
> tabs drawn only when carriers > 1, **default view the AGGREGATE table**, menu counter = the SUM
> with a tooltip breakdown. This spec settles what #694 deliberately left open — the column
> contract, the row scope, and the one thing that blocks an honest aggregate.
>
> **It rests on a measurement #694 could not finish.** That card recorded «чем каждый наполняет семь
> общих колонок, не проверено» as an open condition on its own decision. That measurement is done
> here (M1) and it changes the design.

## What this is

The framework-owned admin page that lists shipping orders across every carrier plugin that declares
one, plus the column/bulk/counter contract a carrier fills in. It replaces the three hand-rolled
`WP_List_Table` pages the shipped v1 plugins each carry.

It does **not** build shipment export, tracking sync, documents or the create-order modal. Those are
SP-7, SP-8 and #710.

## M1. The measurement — the seven "common" columns are not common in the same way

Read across all three shipped plugins' list tables on 07.09.2026
(`plugins-reference/{woocommerce-edostavka,woocommerce-yandex-delivery,woodev-russian-post}`).

| column | finding |
|---|---|
| `customer` | **byte-for-byte identical** in edostavka and yandex — name/email/user link + phone with the dashicon. Copy-paste, down to the same fallback to `display_name` |
| `payment` | **byte-for-byte identical** — `{method title}: {formatted total}` plus the «Заказ может быть неоплаченым» hint gated on `! is_exported() && needs_payment()` |
| `order` | identical modulo text domain: trash → bold label; else an edit link carrying `view=orders&order_id=N`. edostavka additionally renders the WC order-preview link |
| `order_date` | identical: `human_time_diff` under 24 h, else `j M Y`, wrapped in `<time>` with a full-date title |
| `cb` | identical modulo the input name (`order_id[]` vs `id[]`) |
| `delivery` | **same shape, one seam**: destination line + `<span class="description">{method}: {shipping total}</span>`. edostavka always formats the billing address; yandex prefers the PVZ station address and falls back to the formatted destination |
| `status` | ⚠ **NOT common.** Each renders its OWN carrier vocabulary under a shared column name: `status-cdek-*` from `get_order_status()`, `yandex-state-*` from `get_state_status()`, `status-russian-post-*` with values `new/canceled/exported/…` |

Two more facts fell out of the same read:

- **The tracking column is one column under two names.** `cdek_number` and `barcode` hold the same
  thing — the carrier tracking number, linked to that carrier's public tracking URL.
- **The `type` column is DECLARED to the framework, not inferred by it.** Yandex renders «До ПВЗ /
  До двери» from `is_pickup()`; russian-post renders «Курьер / Постамат / Отделение». The framework's
  authority for the same thing is **`Shipping_Method::get_delivery_type()`, which is `abstract`**
  (`class-shipping-method.php:108`) — every shipping method must answer it, and
  `Shipping_Method_Courier` / `_Pickup` / `_Postal` merely `final`-ise it. So this is a framework
  column, and a strong one: a declared contract rather than a guess.

  ⚠ **An earlier draft of this line said to compute it by an `instanceof` chain against those three
  subclasses. That is wrong** and was caught during increment 2a. They are optional convenience
  bases, not the authority: a carrier extending `Shipping_Method` directly declares a perfectly good
  type and would have been classified `unknown` — including `Woodev_Test_Shipping_Method`, which the
  entire integration suite is built on. Resolve the order's shipping line with
  `Shipping_Helper::get_order_shipping_item()`, take the instance through
  `\WC_Shipping_Zones::get_shipping_method()`, and **ask it `get_delivery_type()`**.

**Consequence.** Six of the seven, plus tracking and type, are framework property. `status` is the
only one that cannot be aggregated as it stands — see D4.

## M2. The row scope is a marker meta key

All three select their rows the same way: one `wc_get_orders()` call with a `meta_query` clause
asserting a carrier marker key `EXISTS` (`_wc_edostavka_shipping`, `_yandex_delivery_state_status`),
with a legacy custom query var (`is_edostavka`, `is_yandex_delivery`) on the non-HPOS path. Both also
exclude `wc-cancelled` and `wc-failed` and restrict `type` to `wc_get_order_types( 'view-orders' )`.

This is what makes #694's open condition — «при условии что агрегат дёшев» — answerable: the
aggregate is **the same single query** with `relation => OR` across the providers' marker keys, not
N queries stitched together.

✅ **MEASURED on the rig, 07.09.2026** (WP 7.1 / WC 11.1.0, HPOS on), not assumed. Three orders were
created — one carrying marker key A, one carrying marker key B, one carrying neither — and queried
back through `wc_get_orders()` with `meta_query` `relation => OR` across A and B:

```text
HPOS enabled: yes        created: a=28, b=29, none=30
OR meta_query returned:  [28, 29]     total: 2     (expected [28, 29])
with orderby=date:       total: 2
VERDICT: OR meta_query WORKS on HPOS
```

Exact ids, correct `total` for pagination, and it survives `orderby`. **The aggregate is one query
and #694's condition is discharged.** The probe was a throwaway run from the rig container and was
deleted; it is reproduced above so nobody has to re-run it to trust it.

⛔ **That measurement is true and was NOT sufficient — corrected the same day, and this is the part
worth reading.** It measured the dev rig, which runs **HPOS**. The integration environment runs the
**legacy CPT datastore**, and there `wc_get_orders()` does not support `meta_query` at all:
WooCommerce emits `_doing_it_wrong` (since 9.2.0) and **returns unfiltered results**. Every carrier's
orders come back under every tab, silently. The integration suite caught it as
`Failed asserting that an array does not contain 14`.

```text
wp option get woocommerce_custom_orders_table_enabled
  dev rig  :8973  -> yes   (HPOS)
  tests environment -> no    (legacy CPT)
```

An earlier draft of this section asserted that the legacy path "is supported by the legacy posts path
for certain". **That was false.** The three shipped plugins already knew the answer and this spec's
own M1 read past it: all three pass a **custom query var** to `wc_get_orders()` and translate it into
a `meta_query` through WooCommerce's `woocommerce_order_data_store_cpt_get_orders_query` filter.

**So the row scope has two paths, not one:**

| datastore | mechanism |
|---|---|
| HPOS (`Woodev_Plugin_Compatibility::is_hpos_enabled()`) | `meta_query` directly, as measured above |
| legacy CPT | a framework custom query var carrying the marker keys + a `woocommerce_order_data_store_cpt_get_orders_query` filter translating it, and **no `meta_query` key at all** — its mere presence is what trips `_doing_it_wrong` |

⚠ **The "matches nothing" case is the dangerous one.** Expressed as a `meta_query` sentinel it is
ignored on the CPT path, so "no providers" would return EVERY order rather than none. It must be
asserted on both paths.

**The transferable lesson, and the reason this is written out rather than quietly patched:** a probe
on the rig proves nothing about the integration environment, because the two disagree about the
orders datastore. Anything touching order queries has to be measured on both.

## D1. One page, registered by a registry that mirrors the settings one

**Decision:** an `Orders_Registry` singleton — one page, registered only when at least one provider
is present, providers registering into it. **It lives in the WooCommerce menu and inside
WooCommerce's own admin React app**, registered with `wc_admin_register_page()`:

```php
wc_admin_register_page( [
    'id'     => 'woodev-shipping-orders',
    'title'  => __( 'Заказы доставки', 'woodev-plugin-framework' ),
    'parent' => 'woocommerce',
    'path'   => '/woodev-shipping-orders',
] );
```

⛔ **An earlier version of this decision put the page under the framework's own `woodev` top-level
menu with `add_submenu_page()`. That was wrong on both halves** — operator, 07.09.2026, after seeing
it on the rig:

1. **Wrong menu.** Every shipped v1 plugin registers its orders page under **WooCommerce**, and that
   is where a merchant looks for orders. The only thing v1 got wrong there was registering N pages
   instead of one; the parent menu was right all along. The framework's `woodev` menu is for the
   framework's own surfaces (licences, plugins, settings), not for order management.
2. **Wrong frame.** `add_submenu_page()` gives a bare WP admin screen. `wc_admin_register_page()`
   puts the page *inside the WooCommerce admin React app* — its header, breadcrumbs, navigation and
   styling — which is the frame the merchant already knows from Analytics and Customers.

**Measured on the rig, WC 11.1.0 (07.09.2026), so none of this rests on recall:** `wc_admin_register_page`
exists, and `wc-components`, `wc-navigation`, `wc-store-data`, `wc-experimental`, `wc-currency`,
`wc-date`, `wc-number` are all registered script handles (39 `wc-*` handles in total).

**The registry shape still mirrors `Settings_Page_Registry`** — one aggregator, providers register
into it, capability resolved once, page absent without providers. Only the MOUNTING mechanism
differs, and it has to: a `wc-admin` page has no render callback, because WooCommerce renders the
app and our component is attached from JS (D7).

**`Shipping_Admin::register_pages()` is NOT the seam for this page.** That class mounts
plugin-supplied page slugs and exists because admin page slugs are installed-site URLs the framework
must not derive. It keeps that job for every other carrier page; the orders page is framework
property and is not mounted through it.

**Legacy slugs get a redirect, not a promise.** `Settings_Page_Registry::maybe_redirect_legacy()`
already solves exactly this for settings. A provider may declare its v1 orders-page slug
(`wc_edostavka_orders`, `wc-yandex-orders`); the framework redirects it to the new page with that
carrier's tab preselected. A merchant's bookmark keeps working; the framework still derives nothing.

## D2. The provider descriptor

A carrier registers one `Orders_Provider` describing itself. Everything the page needs is on it, and
nothing on it is derived by the framework:

| field | what it carries |
|---|---|
| `id`, `label` | tab identity and title |
| `marker_meta_key` | the "this order is ours" key from M2 — the framework turns it into the `EXISTS` clause |
| `tracking_url` | template for the tracking number's link |
| `status_map` | carrier status → canonical status (D4) |
| `columns` | carrier-only extra columns, each with a render callback |
| `bulk_actions` | carrier-only extras beyond the three common ones (D5) |
| `legacy_page_slug` | optional, for the D1 redirect |

The framework hardcodes no meta key, no slug and no status string — same rule already enforced by
`Shipping_Order_Handler` and `Shipping_Admin`.

## D3. Column ownership

**Framework-owned, rendered identically for every carrier:** `cb`, `order`, `order_date`,
`customer`, `payment`, `delivery`, `type`, `tracking`. M1 is the evidence: these are already the
same code three times over, or (in `type`'s case) computable from the framework's own method
classes.

**One seam inside `delivery`:** which destination wins. Default — the chosen pickup point when the
order has one (the framework already stores it via `Shipping_Order_Handler::store_pickup_point()`),
else the formatted shipping/billing address. A carrier overrides only if it must.

**Carrier-owned:** anything it declares in `columns`, plus `actions`.

## D4. The canonical delivery status — the minimum slice, and why it is in scope

An aggregate table cannot show three vocabularies in one column. §13 already decided the answer in
s32: a canonical set of nine — `pending / created / in_transit / ready_for_pickup / delivered /
returning / returned / failed / cancelled` — with the carrier's raw label preserved for display.

**In scope here:** the enum, the provider's `status_map`, and rendering — canonical label in the
cell, raw carrier label in the tooltip.

**Explicitly NOT in scope here:** the pull/push sync that MAINTAINS the status (cron, webhooks,
status history, the canonical → WC-status mapping settings). That is SP-8 and stays there. This spec
consumes the status; it does not keep it up to date.

## D5. Bulk actions

Measured common set across all three: `export`, `update`, `cancel`. The framework declares those
three and routes them to the abstractions that already exist —
`Abstract_Shipment_Handler::export()` and `::cancel()`. Carrier extras (yandex's `print_label`,
`print_act`) come from the descriptor.

⚠ `Abstract_Shipment_Handler::export()` currently fires `shipment_exported` even when the response
carries an empty `carrier_order_id`, and `schedule_retry()` retries without a counter or a limit
(measured in s124, recorded on **#819**). The page must not paper over that; whatever it surfaces
per row has to tell a failed export from a successful one honestly.

## D6. The menu counter

Operator's decision, recorded in §16: the `woodev` menu item carries the **sum** across carriers;
the tooltip breaks it down («Всего заказов: 5; СДЭК — 3, Яндекс доставка — 2»). Each tab carries its
own number. All three shipped plugins already write a per-carrier count into their own menu item, so
nothing is lost by aggregating — the breakdown survives in the tooltip and on the tabs.

## D7. WooCommerce's React, not our own

**Decision: the page is a WooCommerce admin React page built on `@woocommerce/components`.** The
operator named the **Customers** report (`…&path=%2Fcustomers`) — 07.09.2026: *«наша таблица должна
быть построена на таких же компонентах примерно с таким же функционалом»* — and then pointed at the
whole **Analytics** section as further reference.

**Both were opened and read on the rig (WC 11.1.0), not imagined.** The closer sibling is
**Analytics → Orders** (`…&path=%2Fanalytics%2Forders`), because it is literally an orders report:

| region | what is there |
|---|---|
| filters row, above everything | «Date range» with **period comparison** (`Month to date vs Previous year`) · «Show» `FilterPicker` · a Data-status panel (Analytics-only) |
| KPI strip | four `SummaryList` tiles with a delta-% badge each, the active one underlined |
| chart | `Chart` — legend checkboxes per series with its total, interval selector («By day»), line/bar toggle |
| table | `TableCard`: title, `⋮` menu, sortable headers with a caret, horizontal scroll, centred empty state, `TableSummary` row |

**What this settles for us:** the per-carrier dimension is the «Show» `FilterPicker` **above the
card**, not tabs. #694 decided *one page, aggregate by default, per-carrier scoping available*; it
did not decide the control, and WooCommerce's own answer for exactly this is a labelled filter. The
delivery-status filter goes on the same row.

**And it settles #711 (ROI/charts) without reopening it:** the chart machinery already exists in
these same packages — series legends, interval, period comparison, line/bar. That card's open
question was always *what to count*, and it stays open; *what to draw it with* is now answered.

⛔ **The first attempt satisfied the letter and missed the point.** It was React — a spinner, state,
`wp-components` — wrapping a hand-written `<table>` in WP core's admin-table markup. Operator, on
the rig: *«Где там React? Спиннер загрузки, да, React. Но таблица то обычная `<table>`.»* A React
component that renders a static table is not the same product as the Customers report, and the gap
is exactly the functionality that comes with the real component.

**What that buys, and what therefore has to be there:** `TableCard` from `@woocommerce/components`
carries sorting, pagination, the summary row, column visibility, CSV download and `isLoading` with a
real `TablePlaceholder` **skeleton** rather than a spinner; `ReportFilters` / `Search` carry live
search and filters. Those are the functional requirements, not decoration.

**The JS attaches through WooCommerce's own filter**, which is how a third party adds a page to
their app:

```js
addFilter( 'woocommerce_admin_pages_list', 'woodev/shipping-orders', ( pages ) => {
    pages.push( { container: OrdersPage, path: '/woodev-shipping-orders', breadcrumbs: [ … ] } );
    return pages;
} );
```

**⚠ The build seam is the one thing to settle by measurement before writing the page.**
`@wordpress/dependency-extraction-webpack-plugin` — what `wp-scripts` ships — does **not** know
`@woocommerce/*` (checked in `node_modules`, 07.09.2026). WooCommerce documents two supported
routes, and both end at the same runtime (`window.wc.components` behind the `wc-components` handle):

| | |
|---|---|
| **A — idiomatic** | add `@woocommerce/dependency-extraction-webpack-plugin` and import `@woocommerce/components` normally |
| **B — documented alternative** | read `window.wc.*` and declare the `wc-*` handles as script dependencies by hand |

A is better to author against; B adds nothing to the build chain. **Which one is taken is decided by
measuring whether A disturbs the other five bundles** — this repo's `Assets build parity` CI job
compares the whole chain, and the repo currently has no root webpack config at all. Measure, then
choose; do not assume either way.

**Route B was taken, and it was forced rather than preferred (measured 07.09.2026).**
`@woocommerce/components` and the WooCommerce dependency-extraction plugin are absent from **both**
`node_modules` and `package-lock.json`, and installing them would mean `npm install` against the
`node_modules` an Orca worktree *shares* with the primary checkout. A real before/after build then
showed the other five bundles' dependency arrays byte-for-byte identical. So: consume
`window.wc.components`, declare `wc-components` / `wc-admin-app` as script dependencies by hand —
which is what Dokan does in production.

✅ **Placement SETTLED by the operator on the rig, 08.09.2026: `FilterPicker` ABOVE the card.** The
first implementation put the carrier selector inside `TableCard`'s `actions` slot because
`TableCard` has no tabs; Analytics → Orders switches report scope with a labelled `FilterPicker`
above the card («Показать → Все заказы»), and both pages were opened side by side before he chose.
Do not reopen it.

⚠ **That choice carries a consequence the `SelectControl` did not have: `FilterPicker` is
URL-driven.** It does not call back with a value — it rewrites the query parameter named by
`config.param` and NAVIGATES (`packages/js/components/src/filter-picker/README.md`; the runtime
contract was also read off the live page, since the shipped bundle is minified and carries no
`propTypes`). Three things follow, all of them implemented:

- the active carrier lives in the `carrier` query parameter, so the view is linkable and the
  browser's back button works on it — the page reads it from the URL, never from its own state;
- the page therefore subscribes to `wc.navigation.addHistoryListener()`, which returns its own
  unlisten function (verified against the live runtime, not recalled), and re-reads the carrier on
  every history change;
- `wc-navigation` joins `wc-components` / `wc-admin-app` in the hand-declared script dependencies —
  Route B applies to `@woocommerce/navigation` exactly as it does to the components.

`config.staticParams` **carries every other filter-row query key, and deliberately not `paged`.**

⚠ **This corrects an earlier version of this line, which said the list was deliberately EMPTY.** The
REASON given there survives and is still the rule — `paged` must not survive a carrier change and
strand the merchant on a page that no longer exists — but the empty list was the wrong instrument
for it, and increment 7 found out why: `FilterPicker` does not carry an unlisted param across its
navigation *at all*, so an empty list silently wiped the entire filter row on every carrier change.
The date range and the advanced filters answer *which work queue view am I in*, independently of
which carrier is scoped, so they must survive. `paged` still does not — it is component state here,
not a URL param, so it cannot be in this list in the first place.

The transferable half: when a decision is recorded as a MECHANISM ("the list is empty") rather than
as its PURPOSE ("`paged` must not survive"), the mechanism is what a later reader defends. State the
purpose.

**The data layer is untouched by all of this.** The registry, the descriptor, the dual-datastore
scope query, the canonical status and the REST row contract are UI-agnostic and survived this
correction unchanged — which is the argument for having built them first.

## Increments

1. ✅ **Registry + descriptor + scope query + REST rows.** No UI. Merged (PR #821).
2a. ✅ **The row payload and the canonical status** (D3, D4). Merged (PR #822).
2b. ⛔ **The page shell — REJECTED on the rig and being rewritten.** The first attempt mounted it in
   the wrong menu with the wrong frame and hand-wrote the table (see D1 and D7). The rewrite:
   `wc_admin_register_page` under WooCommerce + `@woocommerce/components`, with the build seam
   settled by measurement first.
3. **Bulk actions** (D5).
4. **Menu counter and tab counts** (D6).
5. **Legacy slug redirect** (D1) — now targeting `page=wc-admin&path=/woodev-shipping-orders`.

#710 (the «Создать заказ» modal) stays outside this spec — it needs the operator's brainstorm by his
own instruction.

## D8. The delivery-analytics panel is ANNOUNCED in v1, not built (#711)

**Operator, 08.09.2026, on the rig:** ship the frame today, **below** the table — not above it the
way Analytics does — behind a «Скоро» overlay. In his words: *«даже если ROI не войдёт в V2, но
пользователи уже будут видеть что такая возможность будет»*. So #711's placement and presence are
now decided; **what it counts is still open on that card** and nothing here narrows it.

**The frame is WooCommerce's own `SummaryListPlaceholder` + `ChartPlaceholder`**, not a drawing of a
chart — four tiles over a plot area, the shape s125 already found in Analytics. Two consequences
that are easy to get wrong:

- they are LOADING skeletons, so their shimmer must be stopped and `ChartPlaceholder`'s real
  `Spinner` hidden outright — a frozen spinner glyph still reads as a stuck load, which is the one
  impression this panel must not give;
- `ChartPlaceholder`'s own `defaultProps` is `{ height: 0 }`, so a height must be passed or the
  block collapses to nothing.

The frame is `aria-hidden`; the overlay carries the message. If a WooCommerce without those two
components is ever running, the panel renders nothing rather than half of itself.

## D9. «Data status» IS ours — the delivery status is the stale thing, not the list

⚠ **This section replaces an earlier answer that was wrong.** It first read: the list is a live
`wc_get_orders()`, nothing can be stale, the panel is inapplicable. The operator corrected it on
08.09.2026, and he is right — the mistake was scoping «stale» to the QUERY instead of to the DATA
the page exists to show.

What Analytics uses the panel for is the freshness of its imported `wc_order_stats` lookup tables
(`src/Admin/API/Reports/*/DataStore.php`). Our list is indeed live. **But the delivery status inside
each row is not**: it is a stored carrier value, refreshed asynchronously — *«перевозчики обновляют
статусы доставки либо по крону либо по вебхуку»*. So the page shows exactly one thing that goes
stale, and it is the column the page exists for.

**Both refresh paths are real, and measured:**

| path | where it already lives |
|---|---|
| cron | the carrier plugin's own scheduled event — edostavka: `wc_edostavka_orders_update`, a configurable interval in minutes behind an on/off toggle, its own `wc_edostavka_orders` schedule (`includes/class-wc-edostavka-cron.php`) |
| webhook | the FRAMEWORK already owns the seam — `Abstract_Webhook_Handler` (`order/abstract-webhook-handler.php`): route registration, signature verification, payload parsing |

So the two fields map cleanly, and this is the operator's own reading: **«Last updated» reflects
EITHER path** — whichever last changed a status — while **«Next update» exists only when a cron is
what refreshes them**, and is absent for a webhook-only carrier.

**What is missing, and it is one half not two:** «Next update» already has a source —
`wp_next_scheduled()` on the carrier's hook, which edostavka **already renders in its own settings**
(`generate_cron_update_html()` → `views/html-cron-update.php`). **«Last updated» has none.** A grep
across every shipped plugin finds no stored last-sync timestamp anywhere: nothing records when a
status was last refreshed, by either path. That timestamp has to be written, and the natural writers
are the two seams above.

⚠ **The framework does not know the carrier's cron hook name**, and must not guess it. That belongs
on the provider descriptor next to `status_map` / `tracking_meta_key` — the same seam every other
carrier-specific fact on this page already uses (D2).

## D10. Which filters are POSSIBLE — measured against the code, not chosen by taste

The operator asked for «Date range» and «Advanced filters» (#826, #827) on 08.09.2026 and decided
the section gets finished properly rather than deferred. The filter list is not a matter of taste:
a filter has to reach `wc_get_orders()`, and **part of the row is computed at build time**, not
stored. Measured against `Order_Row_Builder`:

| row field | where it comes from | filterable on the server? |
|---|---|---|
| date | native `date_created` | ✅ |
| carrier | marker meta | ✅ — already is; it is the scope query |
| WC order status | native | ✅ |
| tracking present | `get_tracking_meta_key()` | ✅ meta `EXISTS` / `NOT EXISTS` |
| **delivery status** | `get_status_meta_key()` — the carrier's RAW value in order meta | ✅ **but not directly** — see below |
| **delivery type** (курьер/ПВЗ/постамат) | order item → `instance_id` → `WC_Shipping_Zones::get_shipping_method()` → `is_courier_shipping()` | ⛔ **NO** — three hops ending in the shipping zones; nothing to query |
| `needs_payment` | computed from status + gateway | ⛔ no (reachable indirectly through order status) |

**The delivery-status filter needs an inversion, and it is the one real complication.** The meta
holds the carrier's RAW status; the filter is on the CANONICAL one. So `Orders_Query` has to invert
`status_map` — canonical → the list of raw values that map to it — and query `IN`. Each provider
has its OWN map, so on the aggregate that inversion is per-provider and the meta query becomes an
OR across carriers, exactly like the scope query already is.

⛔ **Do not promise a delivery-type filter.** Making it work means denormalising the type into meta
when the order is placed, which is a data change, not a filter — a separate decision, and it would
only ever cover orders placed after it shipped.

⚠ Every one of these must be measured on BOTH datastores. The rig is HPOS, the integration
environment is the legacy CPT, and `wc_get_orders()` has already once dropped a `meta_query` there
and returned an unfiltered result with no error (gotcha
`wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`).

## D11. The filter row, and the default period

**The operator decided on 08.09.2026 to finish this section properly rather than defer it** — «раз
мы уже до этого раздела добрались, то доделываем его основательно». So #826 (Date range) and #827
(Advanced filters) are in scope, and «Advanced filters» means WooCommerce's `AdvancedFilters`
component — he asked for it by name, so that is not an open choice.

**Default period: «Год с начала года» (`period=year`) — settled by the operator, 08.09.2026.**

⚠ **The consequence he was choosing against: WooCommerce's picker has NO «all time».** Its presets
run Today · Yesterday · Week to date · Last week · Month to date · Last month · Quarter to date ·
Last quarter · Year to date · Last year, plus Custom — measured by opening it on the rig, not
recalled. Adopting it therefore makes this list **permanently period-bounded**, which it is not
today. The widest preset was chosen because a delivery-orders list is a work queue: an order stuck
two months ago is exactly the one the merchant opens the page for, and Analytics' own «Month to
date» default would hide it.

**No period COMPARISON.** `DateRangeFilterPicker` carries Analytics' «vs. Previous year», which
answers a question about dynamics. This is a list of orders, not a trend, so the compare control is
not wired and `compare` is not sent to the server.

**Everything in the row is URL-driven**, like the carrier picker already is (D7): filters live in
the query, the view stays linkable, and the back button works across all of them.

**Contract note for the date args:** `@woocommerce/date` is reached the Route-B way like everything
else — `window.wc.date` behind the `wc-date` handle — and supplies `getDateParamsFromQuery()`,
`getCurrentDates()` and `isoDateFormat` (`YYYY-MM-DD`), which is what
`DateRangeFilterPicker`'s required `dateQuery` prop is built from.

## Increments, continued

6. **The filter row, server half** (#826, #827 — D10, D11). `Orders_Controller` accepts the new
   args; `Orders_Query::build_args()` turns them into `wc_get_orders()` args: `date_created` for the
   range, the inverted `status_map` for the delivery status, native `status`, and a meta
   `EXISTS`/`NOT EXISTS` for tracking presence. **No UI.** ⚠ Proven on BOTH datastores or not proven
   — the legacy-CPT path already needs `translate_marker_keys_query_var` for the marker key, and
   every new meta condition has to survive the same translation.
7. **The filter row, client half.** `DateRangeFilterPicker` + `AdvancedFilters` beside the carrier
   `FilterPicker`, all reading and writing the URL.
8. **«Data status»** (#828, D9) — needs the last-sync timestamp built first; it is not a UI task.

## What this does NOT do

- Does not build shipment export, documents, tracking sync or webhooks (SP-7, SP-8).
- Does not maintain the delivery status, only renders it (D4).
- Does not touch `Shipping_Admin`'s per-carrier page mounting (D1).
- Does not decide the create-order modal (#710).

## Related

- #694 — the settled page shape, and the measurement condition this spec discharges
- #710, #711 — the two deferred halves of SP-10
- #819 — the export-error measurement D5 must not paper over
- `archive/SHIPPING-PLANS.md` §12, §13, §16 — the s32 decisions this builds on
- `specs/2026-06-25-shipping-module-decisions.md` — the SP track and its dependency order
