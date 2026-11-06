# #711 — Delivery analytics («Аналитика» tab): data research and implementation spec

**Status:** IMPLEMENTED — merged as PR #1565 on 03.11.2026 (stages 2 and 3, s212; #711 closed). See §9 for where the implementation diverged from this text.
**Date:** 2026-11-03 (s212)
**Scope:** framework only (`woodev-plugin-framework`); every carrier at once; **no framework release** (operator, #711 comment 03.11.2026).
**Evidence:** framework paths are relative to this worktree (branch `kalbac/711-roi-analytics`, base `3dc1d019`, framework 2.0.23),
read through Serena. Carrier plugins are the primary checkouts (read-only): `cdek:` = `/Users/maksimmartirosov/Projects/woocommerce-edostavka`
(2.3.0.13), `yandex:` = `…/woocommerce-yandex-delivery`, `rp:` = `…/woodev-russian-post`, `ozon:` = `…/woodev-ozon-delivery`.
WooCommerce API facts come from Context7 (`/woocommerce/woocommerce`) and the rig's installed WC (**11.2.1**, not 11.1.0 as older
docs say; HPOS **on**). Labels: **READ** (code), **MEASURED** (rig query), **interpretation** (a gap this spec closes, listed in §8).

## 0. Binding operator decisions (#711 comment 03.11.2026) — not reopened here

1. v1 counts only what is ALREADY stored in orders: orders and revenue per carrier and per shipping method; shares delivered / refused /
   returned per carrier; average time handover → delivery; comparison with the previous period. «Paid for shipping vs actual carrier cost» only
   where the actual cost is already saved — establish for which carriers first (§3).
2. A separate «Аналитика» tab on «Заказы доставки»; the orders list does not change. Charts on `wc-components`.
3. Lives in the framework; no framework release tonight.
4. Visuals are his taste: PR + Codex review + tests, not merged.
5. Stage 2 (later): a report in WooCommerce «Аналитика» (`path=/analytics/…`) over the SAME REST data.

## 1. Data inventory

### 1.1 The page and its plumbing (question A)

| Fact | Where |
|---|---|
| Page = `wc_admin_register_page()` under the WooCommerce menu, slug `woodev-shipping-orders`, capability `Orders_Registry::get_page_capability()` | `woodev/shipping-method/admin/orders/class-orders-registry.php:814-823`, `:958-969`, `:665` |
| Script/deps: `[…asset deps, 'wc-components','wc-navigation','wc-admin-app','wc-date','wc-currency','heartbeat']`; **never `wc-settings`**; bootstrap object `window.woodevShippingOrders` (`restRoot`, `nonce`, `providers`, `deliveryStatuses`, …) | `:1649-1745` (deps `:1708`, inline bootstrap `:1727`) |
| JS entry pushes `{ container: OrdersPage, path: '/woodev-shipping-orders', wpOpenMenu }` onto `woocommerce_admin_pages_list` | `src/shipping-orders-page/index.tsx:20-37` |
| Page state is URL-driven (`getQuery()` / `updateQueryString`); `OrdersPage` is one 2 972-line component; there is **no router and no tabs** today | `src/shipping-orders-page/app.tsx:169`, `:1630` |
| A **placeholder** for exactly this feature already exists: `RoiPanel` («Скоро» overlay on `SummaryListPlaceholder` + `ChartPlaceholder`), rendered BELOW the table (operator 08.09.2026). The tab replaces it | `app.tsx:1373-1410`, SCSS `.woodev-orders-roi*` in `style.scss` |
| Typed `window.wc.*` surface of the page; `Chart`, `SummaryList`, `SummaryNumber`, `DateRangeFilterPicker`, `ReportFilters`, `TableCard`, `ChartPlaceholder` are all exported by the installed `wc-components` bundle (**MEASURED**, rig WC 11.2.1: `assets/client/admin/components/index.js`); `@woocommerce/date` exports `getDateParamsFromQuery`, `getCurrentDates`, `getIntervalForQuery`, `getAllowedIntervalsForQuery`, `getChartTypeForQuery`, `getPreviousDate` | `src/shipping-orders-page/wc-globals.d.ts:285-330` (only `TableCard`, placeholders, `DateRange`, `AdvancedFilters`, `Link` are declared today — the rest must be added) |
| REST: namespace `woodev/v1` (`\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE`); list `GET /shipping/orders` gated by `current_user_can( get_page_capability() )`; existing sibling routes `/shipping/orders/sync-status`, `/shipping/orders/{id:\d+}/…`, `/shipping/orders/toolbar-actions` — a literal `/shipping/orders/analytics` collides with none | `woodev/shipping-method/rest-api/class-orders-controller.php:97-246`, `:303-314`; routes registered from `rest-api/class-shipping-rest-api.php` |
| The list queries through `wc_get_orders( meta_query )` (datastore-agnostic) and, since #928, resolves ids with ONE flat statement against `wc_orders_meta` (HPOS) or `postmeta` (legacy): driver row per (order, marker) + correlated `EXISTS` subqueries; the table is chosen by an `$hpos` flag | `admin/orders/class-orders-id-resolver.php` (class doc, `:417-435`); `Orders_Query::is_hpos_enabled()` `class-orders-query.php:1548` |

### 1.2 What is stored per shipping order (question B)

| Needed | Stored? | Key / source | Notes |
|---|---|---|---|
| Carrier | yes | order meta `Orders_Provider::get_marker_meta_key()` (one key per provider, **non-empty scalar value**); first registered provider with a non-empty marker wins (`Orders_Registry::resolve_provider_for_order()` `:536`) | an order MAY carry several markers (CDEK writes one per package); the rule «first wins» must be reproduced in SQL |
| Shipping method | yes | WC shipping line: `woocommerce_order_items` (`order_item_type='shipping'`) + itemmeta `method_id`, `instance_id`, `cost`, `total_tax` (**MEASURED** on the rig; `total` does not exist) | several lines per order are possible |
| Shipping paid by the buyer | yes | HPOS `wc_order_operational_data.shipping_total_amount` + `shipping_tax_amount`; legacy postmeta `_order_shipping` + `_order_shipping_tax` | **MEASURED** columns on the rig |
| Order total | yes | HPOS `wc_orders.total_amount`; legacy `_order_total` | `wc_order_stats` also has it but is an async Action-Scheduler lookup, empty on the rig → **not used** |
| Order date | yes | HPOS `wc_orders.date_created_gmt`; legacy `post_date_gmt` | |
| Raw carrier status | yes | meta `Orders_Provider::get_status_meta_key()` (CDEK `_wc_edostavka_status`, Yandex `_yandex_delivery_state_status`, Ozon `_woodev_ozon_delivery_status`, Russian Post per `Russian_Post_Tracking_Status`) | `null` key = provider has no status concept → always `unknown` |
| Canonical status | derived | raw → `Delivery_Status` via the provider's `get_status_map()`; an unmapped raw = `unknown`; `Delivery_Status::invert_status_map()` already gives canonical → raw[] for SQL `IN` (`order/class-delivery-status.php`) | the 9 states + `unknown`: pending, created, in_transit, ready_for_pickup, delivered, returning, returned, failed, cancelled |
| Cancellation | yes | framework meta `_woodev_shipment_cancelled_at` (`Shipment_Cancellation::CANCELLED_AT_META`, a Unix time) → canonical `cancelled` whatever the raw says | `Delivery_Status::resolve( …, $cancelled )` |
| **Status HISTORY with dates** | **NO** | the framework keeps only the LAST canonical state published, `_woodev_delivery_status_published_{provider}` (no time; `order/class-delivery-status-events.php:39`, `remember()`), and `_woodev_delivered_status_applied_{provider}` = `'1'` (no time; `class-delivered-order-status.php:46,87-100`) | confirmed on the rig: 7 `…_published_yandex_delivery` rows, none carries a date |
| Handover (shipped) date | **NO uniform field** | CDEK `_wc_edostavka_snapshot_at` = time of the FIRST status read after export (`cdek: includes/tracking/class-cdek-order-snapshot.php:44`, `class-cdek-status-synchronizer.php:167-202`), not the handover; Ozon `status_changed_at` per posting inside `_woodev_ozon_postings` (serialised array); Yandex `state_history` exists only in the live API answer (`yandex: includes/tracking/class-yandex-tracking-handler.php:26-49`); Russian Post keeps events in a transient (`woodev_wc_russian_post_history_*`) and a poll time | no common, queryable timestamp |
| Delivered date | **NO uniform field** | indirect only: WC `date_completed` — HPOS `wc_order_operational_data.date_completed_gmt`, legacy `_date_completed` — set when `Delivered_Order_Status::apply()` moves the order to the merchant's «delivered» status **and that status is `completed`**; or when the merchant completes it by hand | covers a subset; see §2.4 |
| HPOS vs legacy | both must work | follow the #928 resolver: pick the table pair by one `$hpos` flag (`Orders_Query::is_hpos_enabled()`); HPOS may run in sync mode — the flag, not the table presence, decides | |

**Consequence (stated plainly): the framework stores no status history, no handover timestamp and no delivered timestamp.** The
operator's «average time from handover to delivery» cannot be computed exactly from what is stored. §2.4 gives the honest v1
substitute and §8 F2 the forward-only fix.

## 2. Metrics — exact definitions

### 2.1 Population, period, previous period

- **Population** = orders that carry at least one provider marker (see §1.2, first provider wins), `type = shop_order`, and a WC status NOT in
  `EXCLUDED = { wc-pending, wc-failed, wc-cancelled, wc-refunded, wc-checkout-draft, auto-draft, trash }` (filterable:
  `woodev_shipping_analytics_excluded_statuses`). «Cancelled/failed WC order» is not revenue and not a delivery outcome. A shipment cancelled at the
  carrier but whose WC order is still live is kept and shows as `cancelled` (§2.3).
- **Period is defined by the ORDER DATE** (`date_created_gmt`), not by handover or delivery date. Justification: it is the only date every
  order has; it never changes after the fact (a handover-date period would move orders between periods and would drop everything the
  carrier has not scanned yet); it is the date the orders list filters by, so «N orders» in the tab equals «N orders» in the list for the
  same range (§7 parity test). Cost: delivery outcomes are cohort values — young orders are still in transit, so the shares use a
  *resolved* denominator (§2.3) and the tab shows «в пути» separately.
- `after` / `before` are store-timezone calendar days, both inclusive. The SQL range is the half-open GMT interval
  `[ local(after 00:00) , local(before+1 day 00:00) )` converted with `wp_timezone()`; strict `>=` / `<`. The implementer verifies on the
  rig that the list route with the same `after`/`before` returns the same count (day-edge parity).
- **Previous period** is sent by the client explicitly as `compare_after` / `compare_before` — the UI takes them from
  `@woocommerce/date` `getCurrentDates( query ).secondary` (`compare=previous_period|previous_year`), so the server never re-derives
  WooCommerce's period arithmetic. No `compare_*` → `previous: null`, no deltas.
- Currency: the store currency (`get_woocommerce_currency()`). Orders in another currency are not converted (multi-currency is out of scope); the
  response carries `currency` once.

### 2.2 Money metrics (per carrier, per shipping method, total)

| Metric | Formula (over the population, current period) |
|---|---|
| `orders` | `COUNT(DISTINCT order)` |
| `revenue` | `SUM(order_total)` (gross, includes shipping; refunds NOT netted — v1) |
| `shipping_paid` | `SUM(shipping_total + shipping_tax)` — what the buyer paid for delivery |
| `avg_order` | `revenue / orders` (null when `orders = 0`) |
| `avg_shipping` | `shipping_paid / orders` (null when `orders = 0`) |
| `shipping_share` | `shipping_paid / revenue` (null when `revenue = 0`) |

Per **shipping method**: group the shipping LINES by itemmeta `method_id`: `lines` = `COUNT(line)`, `shipping_paid` = `SUM(line.cost + line.total_tax)`,
`orders` = `COUNT(DISTINCT order)`. Method rows are NOT additive (an order with two methods counts in both) — the table says so in a
footnote, and the totals row always comes from the carrier aggregate. **Revenue per method (critic round 1, acceptance item 1):** `revenue` = the sum of ORDER totals, each order once per distinct (carrier, method, order) — an order with two lines of one method adds its total once; an order with two different methods adds it to both rows (hence the footnote). It is not a split of the total across lines, which cannot be done honestly. Each method row is attributed to the carrier whose `get_method_ids()` contains the `method_id`; a method no provider claims goes to the
carrier of the order's marker.

### 2.3 Delivery outcomes (per carrier, total)

Buckets of the CANONICAL state (cancellation marker first, then raw → `status_map`, unmapped → `unknown`):

| Bucket (UI label) | Canonical states |
|---|---|
| `in_progress` «В пути / в работе» | `pending`, `created`, `in_transit`, `ready_for_pickup` |
| `delivered` «Доставлено» | `delivered` |
| `returned` «Отказ / возврат» | `returning`, `returned` |
| `failed` «Не доставлено» | `failed` |
| `cancelled` «Отменено» | `cancelled` (shipment cancelled at the carrier) |
| `unknown` «Нет статуса» | `unknown` (no status meta, or an unmapped raw value) |

**There is no canonical «refused by the buyer» state.** The carriers' maps send a buyer refusal to `returning`/`returned` (**READ**: CDEK
`class-cdek-delivery-status.php:62` `POSTOMAT_SEIZED`; Yandex `class-yandex-delivery-status.php:64-73` the whole `RETURN_*` family; Russian Post
`class-russian-post-tracking-status.php:90-93`), while `failed` mixes real delivery failures with export/acceptance errors (Yandex
`EXPORT_INVALID`, `VALIDATING_ERROR`; Ozon `forming_failed`, `not_accepted_to_delivery`; CDEK `NOT_DELIVERED`, `INVALID`). So the operator's
«refused» and «returned» are ONE bucket here (`returned`), `failed` is its own, and the UI never claims «buyer refused» (fork F3).

- `resolved = delivered + returned + failed`.
- `delivered_share = delivered / resolved`, `returned_share = returned / resolved`, `failed_share = failed / resolved` — null when `resolved = 0`.
- `in_progress`, `cancelled`, `unknown` are shown as counts, excluded from `resolved`; `cancelled`/`unknown` are excluded from the share
  denominators on purpose (they say nothing about delivery quality).
- An order with a non-empty marker and **no** status meta is `unknown` (the commonest unknown — not hidden, counted).

### 2.4 Time to delivery

The exact metric needs two timestamps nobody stores (§1.2). **v1 ships this substitute, labelled honestly** («Срок от оформления заказа до вручения»):

`avg_days_to_delivery = AVG( date_completed − date_created )` in days, over orders that are canonical `delivered` AND have
`date_completed > date_created` (HPOS `date_completed_gmt`, legacy `_date_completed`). `time_sample` = the number of such orders; the tile shows
«по N из M доставленных». With `time_sample = 0` (typical when the merchant's «delivered» status is not `completed`) the tile reads «Нет данных»
with a one-line reason. Bounded to `0 < days ≤ 180` to keep a manually completed outlier from poisoning the mean (the bound is a constant in
code, not a setting). This is the order-to-delivery lead time, not the carrier's transit time; the label says so.
Proper handover → delivery needs the forward-only log of F2 — built in #1564 (§9 row 18); the tile switches to the exact figure once an order's log shows both ends.

## 3. The carrier-cost metric — **OUT of v1** (verdict)

Question: for which carriers is the ACTUAL carrier cost (what the shop pays) already saved in the order?

| Carrier | What is stored | Key / when | Actual cost the shop pays? |
|---|---|---|---|
| **CDEK** | Framework fact baseline, two components from the order read: `delivery` = `delivery_detail.delivery_sum`, `total` = `delivery_detail.total_sum` (services included) — `cdek: includes/tracking/class-cdek-shipment-facts-mapper.php:48-58` | order meta `_woodev_shipment_fact_cost_edostavka` (`array<component,{amount,currency}>`), written by `Shipment_Facts_Events::record()` on each status read after export (`woodev/shipping-method/order/class-shipment-facts-events.php:66`, `stage_cost()`) | **A carrier figure of unstated ownership.** The framework's own note says «чья это стоимость — по договору или для получателя — он может не уточнять» (`cost_note()`), and the code comment on the hook says the same. Latest value only, no history. Present only on orders read since CDEK 2.3.0.0. |
| **Ozon** | Per-posting `estimated_delivery_cost` (an ESTIMATE by its API name) summed into one `delivery` fact — `ozon: includes/tracking/class-ozon-status-poller.php:632-675` (`facts()`, `with_cost( $total, $currency )`); the checkout quote `_ozon_carrier_price` is on the **shipping line**, in roubles (`ozon: includes/shipping/class-ozon-rate-method.php:199`) | `_woodev_shipment_fact_cost_{ozon id}` + line meta | **No** — an estimate, and a quote (re-quoted at export, `class-ozon-export-request.php:181-195`, which only writes an order note on change). |
| **Yandex** | Only the checkout QUOTE on the shipping line: `_yandex_delivery_carrier_price` (calculator `pricing_total`, no merchant markup) and `_yandex_delivery_delivery_cost` — `yandex: includes/shipping/class-yandex-pricing.php:29-41` | line meta at checkout; the class doc says it is «NOT the amount collected from the recipient» | **No** — a quote at checkout time; no `with_cost()` call anywhere in the plugin. |
| **Russian Post** | Nothing on the order. `total-rate` / `total-vat` are computed at checkout/rate time and not persisted | — | **No.** (Order-meta constants of the plugin: `_tnved_code`, origin country, tracking mail flags, poll time — none is a price.) |

Verdict: only CDEK reports a figure from the carrier's own order read, and even that is of unstated ownership (contract tariff vs recipient
price); Ozon's is an estimate; Yandex's a checkout quote; Russian Post has none. Subtracting any of them from «shipping paid» would produce a
margin number nobody can defend. **The metric is OUT of v1** (fork F1, provisional). The tab does not show «customer paid vs carrier cost».
The inventory above is the entry point if a carrier later states its contract cost (a `Shipment_Facts` component such as `contract`, no
framework change needed to store it).

## 4. REST endpoint

`GET /wp-json/woodev/v1/shipping/orders/analytics` — new `Analytics_Controller` in `woodev/shipping-method/rest-api/`, registered by `Shipping_Rest_Api`
next to `Orders_Controller`; the aggregate SQL lives in a separate, unit-testable `Analytics_Query` (see §7) in `woodev/shipping-method/admin/orders/`.

**Params** (all validated; unknown → ignored)

| Param | Type | Default | Rule |
|---|---|---|---|
| `after`, `before` | `Y-m-d` | required | `checkdate`; `after ≤ before`; span ≤ 800 days else 400 `woodev_shipping_analytics_range_too_wide` |
| `compare_after`, `compare_before` | `Y-m-d` | absent | both or neither; same validation |
| `interval` | `day`\|`week`\|`month`\|`auto` | `auto` | `auto` = day ≤ 31 days, week ≤ 180, else month (the WC `getIntervalForQuery` rule, mirrored client-side for display) |
| `carrier` | provider id \| `all` | `all` | must be a registered provider id else 400 |
| `refresh` | bool | false | bypasses the cache (same capability) |

**Permission:** `current_user_can( Orders_Registry::get_page_capability() )` (same as the list GET — a 403, never a silent empty body).
Read-only; no nonce beyond the cookie `X-WP-Nonce` the page already sends. Revenue is financial data: the capability is the page's and must not be
weakened.

**Response**

```jsonc
{
  "range":    { "after": "2026-10-01", "before": "2026-10-31", "interval": "day" },
  "compare":  { "after": "2026-09-01", "before": "2026-09-30" },            // null when not requested
  "currency": "RUB",
  "totals":   { "current": Metrics, "previous": Metrics | null, "delta": { "orders": 12.5, "revenue": null, … } },
  "carriers": [ { "id": "edostavka", "label": "СДЭК", "current": Metrics, "previous": Metrics | null, "delta": {…} } ],
  "methods":  [ { "method_id": "edostavka_pickup", "carrier_id": "edostavka", "label": "…", "orders": 40, "lines": 41, "revenue": 98765.0, "shipping_paid": 12345.0 } ],
  "intervals":          [ { "start": "2026-10-01", "end": "2026-10-01", "totals": Series, "carriers": { "edostavka": Series } } ],
  "previous_intervals": [ … same shape … ] | null,
  "coverage": { "delivered": 120, "time_sample": 87 },
  "generated_at": "2026-11-03T05:00:00+00:00", "cached": true
}
// Metrics = { orders, revenue, shipping_paid, avg_order, avg_shipping, shipping_share,
//             outcomes: { in_progress, delivered, returned, failed, cancelled, unknown },
//             resolved, delivered_share, returned_share, failed_share, avg_days_to_delivery, time_sample }
// Series  = { orders, revenue, shipping_paid, delivered, resolved }      // the chart's metrics, incl. delivered_share = delivered/resolved
// delta   = percent change current vs previous, null when previous is 0/absent
```

Money is a JSON number rounded to 2 decimals (the client formats with `@woocommerce/currency`). `label` comes from `Orders_Provider::get_label()`;
the method label from `WC()->shipping()->get_shipping_methods()` (fall back to the raw `method_id`). Carriers with no orders in either period are omitted.
`outcomes` and `delivered_share` are NOT in `Series` for `previous_intervals` beyond what the chart overlays (orders, revenue, shipping_paid).

### 4.1 SQL — shape (no PHP loop over orders)

Mirror the #928 form: a **driver** row per (order, provider marker), everything else a correlated scalar subquery with `ORDER BY id DESC LIMIT 1`
(duplicate-meta safe), one `UNION ALL` leg per registered provider (typically 1–5 legs), outer `GROUP BY`. `{om}` = `wc_orders_meta` /
`postmeta`; `{o}` = `wc_orders` / `posts`; `{ord_col}` = `o.id` / `o.ID`.

```sql
-- HPOS, one leg per provider P (marker key MK, status key SK or none, cancel key = _woodev_shipment_cancelled_at)
SELECT %s AS carrier,
       o.id AS order_id,
       o.total_amount                             AS total,
       COALESCE(op.shipping_total_amount,0) + COALESCE(op.shipping_tax_amount,0) AS ship,
       DATE(DATE_ADD(o.date_created_gmt, INTERVAL %d SECOND)) AS day,          -- site offset, see below
       CASE
         WHEN EXISTS (SELECT 1 FROM {om} c WHERE c.order_id = o.id AND c.meta_key = '_woodev_shipment_cancelled_at' AND c.meta_value <> '') THEN 'cancelled'
         ELSE COALESCE( ( SELECT CASE
                 WHEN s.meta_value IN (<raw[delivered]>) THEN 'delivered'
                 WHEN s.meta_value IN (<raw[returning],raw[returned]>) THEN 'returned'
                 WHEN s.meta_value IN (<raw[failed]>) THEN 'failed'
                 WHEN s.meta_value IN (<raw[pending],raw[created],raw[in_transit],raw[ready_for_pickup]>) THEN 'in_progress'
                 WHEN s.meta_value IN (<raw[cancelled]>) THEN 'cancelled'
               END FROM {om} s WHERE s.order_id = o.id AND s.meta_key = %s ORDER BY s.id DESC LIMIT 1 ), 'unknown')
       END AS bucket,
       op.date_completed_gmt AS completed_gmt, o.date_created_gmt AS created_gmt
FROM   {om} mk
JOIN   {o}  o  ON o.id = mk.order_id AND o.type = 'shop_order'
LEFT JOIN wc_order_operational_data op ON op.order_id = o.id
WHERE  mk.meta_key = %s AND mk.meta_value <> ''
  AND  o.status NOT IN (<EXCLUDED>)
  AND  o.date_created_gmt >= %s AND o.date_created_gmt < %s
  AND  NOT EXISTS ( <marker of any EARLIER provider with a non-empty value> )   -- «first provider wins»
```

The inner `CASE` (an empty `IN ()` list is omitted — never emitted) is built from `Delivery_Status::invert_status_map( $provider->get_status_map() )`, the
same inversion `Orders_Query::status_map_clauses()` uses, so the tab and the list's «delivery status» filter agree by construction. A provider
whose `get_status_meta_key()` is `null` emits the literal `'unknown'`.

Legacy leg: `{o}` = `{posts}` (`o.ID`, `post_type = 'shop_order'`, `post_status`, `post_date_gmt`), money via scalar subqueries
`CAST( (SELECT meta_value FROM {postmeta} … meta_key = '_order_total' …) AS DECIMAL(18,4) )` (also `_order_shipping`, `_order_shipping_tax`),
completed time `FROM_UNIXTIME( _date_completed )`. Same legs, same outer queries.

Three outer statements share the legs (as a derived table or a `WITH`-free subquery — MySQL 5.7/MariaDB 10.x compatible):

1. **Carrier aggregates:** `GROUP BY carrier` → `orders`, `SUM(total)`, `SUM(ship)`, `SUM(bucket=…)` per bucket, `AVG`/`COUNT` of `TIMESTAMPDIFF(SECOND, created_gmt, completed_gmt)` where bucket = `delivered` and `0 < diff ≤ 180 d`.
2. **Interval series:** `GROUP BY carrier, day` → `orders`, `SUM(total)`, `SUM(ship)`, delivered count, resolved count. `week`/`month` are rolled up in PHP from the DAY rows (≤ 800 days × carriers rows — a loop over aggregate rows, never over orders). Day boundaries use ONE fixed site offset for the request (`wp_timezone()->getOffset()` at the range midpoint): orders within an hour of midnight on a DST-change day can land on the neighbouring day — accepted, noted in a code comment (fork F5).
3. **Methods:** `FROM {woocommerce_order_items} i JOIN itemmeta mid ON … 'method_id' JOIN itemmeta t ON … 'total' / 'total_tax'` restricted to the population order ids of (1) via a join to the same legs → `GROUP BY method_id`.

Prepared with `$wpdb->prepare()` throughout; provider ids, meta keys and raw status values are bound, never interpolated (`IN` lists are placeholder-expanded). The previous
period runs the SAME statements with its own bounds (3 + 3 queries, plus the carrier-filter variant). `carrier=X` restricts to one leg.

### 4.2 Caching and invalidation

- Transient `woodev_shipping_analytics_{md5( params + provider signature + hpos flag + excluded statuses + data version )}`; `data version` is the option
  `woodev_shipping_analytics_version` (autoload `no`).
- TTL: **10 minutes** when the range contains today (store time), **1 hour** otherwise. The tab's «Обновить» control sends `refresh=1`.
- Invalidation = bump the version, **debounced to once per 60 s** (a short transient lock), on `woodev_shipping_delivery_status_changed`,
  `woocommerce_new_order`, `woocommerce_order_status_changed`, `woodev_shipping_order_exported`, **`woocommerce_update_order`** (any save — a money or shipping-method edit changes no status) and `woocommerce_trash_order` / `untrash_order` / `delete_order` (critic round 1). A busy shop therefore writes one option per minute at most.
- The provider signature (`md5` of ids + status-map hashes + marker keys) keeps a plugin update that changes a `status_map` from serving stale buckets.
- Worst case with the cache cold = the 3–6 aggregate statements once per ten minutes per distinct filter set; the endpoint is rate-limited by the cache itself
  (a request storm hits the transient, not the DB). No cron, no per-order writes, no calls to the carriers.

### 4.3 Performance (question E)

Plan, not a measurement: every leg is an index range scan on `meta_key` (`wc_orders_meta.meta_key_value` / `postmeta.meta_key`) joined to the order by primary key, plus
≈ 3 indexed single-row subqueries per driver row (status, cancelled marker, optional duplicate guard) — linear in the orders that carry a marker, the same
complexity class as the #928 id query that is already measured at 10 000 orders. The date predicate runs on `date_created_gmt`; a note from gotcha
`narrowing-the-id-query-by-a-date-window-only-pays-below-a-few-percent-of-the-store` is that a date window does not shrink the driver scan, so the cost is
bounded by marker rows, not by the range. **Stage 2 must measure** (not assume): seed 50 000 marker orders on the tests DB (port 8974), `EXPLAIN` each statement, record the
cold time in the PR; the acceptance bar proposed here is «cold < 3 s on 50 000 orders, warm < 50 ms» — if the cold bar is missed the first fallback is a
nightly Action-Scheduler materialisation (§8 F6), not iterating orders.

## 5. UI — the «Аналитика» tab

**Tabs.** `OrdersPage` gains a two-tab header «Заказы» | «Аналитика», URL-driven like every other control: `tab=analytics` (absent = orders list, the default,
so every existing link and the legacy redirect keep working). Switching goes through `getNewPath( { tab }, '/woodev-shipping-orders', {} )` / `updateQueryString`, clearing the
list's own filters (`after`/`before`/`status`/…): the list and the analytics tab both use `after`/`before`, and `wc-admin` re-adds date params a link does not carry (gotcha
`wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry`) — the tab owns its own param set (`period`, `compare`, `after`, `before`, `interval`, `chart`, `carrier`) and the list ignores them.
The orders list component is NOT changed except for the tab switch wrapper; the `RoiPanel` placeholder is removed together with its SCSS.
**Do not grow `app.tsx`** (2 972 lines): the tab is a new module `src/shipping-orders-page/analytics/` (`analytics-tab.tsx`, `use-analytics.ts`, `analytics-rest.ts`,
`format.ts`, `types.ts`), imported lazily by `OrdersPage` (`React.lazy` is not available in the wc-admin bundle context → plain import, the code is small).

**Layout (top to bottom)**

1. **Filter row:** `DateRangeFilterPicker` (WC's own, with `compare` = previous period/year and presets — «Всё время» is not meaningful for an aggregate and is intentionally absent),
   a carrier `SelectControl` («Все перевозчики» + the page's `providers`), an «Обновить» button (`refresh=1`).
   `getDateParamsFromQuery` / `getCurrentDates` from `window.wc.date` resolve `period`/`compare` to `primary`/`secondary` ranges. The tab's default
   (passed as the `defaultDateRange` argument) is `period=last_30_days&compare=previous_period` (fork F4; WC's own report default is `month` vs `previous_year`).
2. **KPI tiles** — `SummaryList` of `SummaryNumber` (the selected tile drives the chart via `chart=`): «Заказы», «Выручка», «Оплачено за доставку», «Доставлено, %»
   (+ sub-label «в работе N»), «Отказы и возвраты, %», «Срок до вручения» (with «по N из M»). Each shows `delta` vs previous (`reverseTrend` for the two bad-outcome tiles), `prevValue` formatted, `prevLabel` from the compare choice. Money via
   `window.wc.currency` (`CurrencyFactory`) — the page already depends on `wc-currency`.
3. **Chart** — `Chart` (`mode="item-comparison"` when several carriers, one series per carrier; `time-comparison` when one carrier), line/bar toggle (`chartType`), `interval` from `allowedIntervals`,
   `itemsLabel` «Перевозчики», `isRequesting` while loading, `emptyMessage` when the series is all zeros. The metric plotted = the selected KPI tile (orders / revenue / shipping_paid / delivered_share).
4. **«По перевозчикам»** `TableCard` — columns: Перевозчик · Заказы · Выручка · Оплачено за доставку · Доставлено · В пути · Отказ/возврат · Не доставлено · Нет статуса, share cells in %. Sortable client-side (≤ 10 rows), no pagination.
5. **«По способам доставки»** `TableCard` — Способ · Перевозчик · Заказы · Оплачено за доставку; footnote «Заказ с несколькими способами учитывается в каждом».

**States.** Loading: `ChartPlaceholder` + `SummaryListPlaceholder` (already typed). Empty (no shipping orders in the range): one `EmptyContent`-style message «За выбранный период нет заказов с доставкой перевозчиками» + a link to widen the range, tiles hidden. Error (non-200 / network): a notice with the server `message`
and «Повторить»; never a blank panel. A slow request keeps the previous data dimmed (no flash). Hidden/zero data never renders «NaN»: every ratio is null-safe server-side and the client prints «—».

**i18n.** Source language of JS strings is Russian via `__( '…', 'woodev-plugin-framework' )` from `@wordpress/i18n` (admin render path — Russian msgid allowed, AGENTS.md → Conventions
i18n rule 2); every new string goes into the catalogue so `npm run lint:js-i18n` and `lint:mo` stay green (same recipe as the orders page strings); PHP-side labels
(bucket names in the REST payload) are NOT sent — the client owns the wording, the server sends keys. `lint:i18n-sources` covers PHP msgids only (the controller has errors with `__()` in Russian — admin path).

**TypeScript.** `src/` is TS-by-default; the new modules are `.ts`/`.tsx` with strict types for the REST response (`types.ts` mirrors §4); `window.wc.components` gains optional
declarations for `Chart`, `SummaryList`, `SummaryNumber`, `DateRangeFilterPicker` in `wc-globals.d.ts` (props read from the installed bundle, as the file's own header demands, not recalled);
`npm run typecheck` and `npm run lint:ts-baseline` must not regress (no new baseline entries). Bundles are built in the PRIMARY checkout only (gotcha `local-npm-run-build-is-not-assets-parity-evidence`).
Styles: a small `analytics.scss` imported from `style.scss`; reuse WC component styling, no new colours except the existing `$warn`/`$error` tokens.

## 6. Stage 3 — the report in WooCommerce «Аналитика» (BUILT, s212)

The same body as the page's «Аналитика» tab, on `/analytics/woodev-delivery`, over the same REST route. Checked against the installed WooCommerce
(11.2.1: `src/Internal/Admin/Analytics.php`, `src/Admin/PageController.php`, `assets/client/admin/app/index.js`) and the reports README (Context7).

- **PHP — `Orders_Registry::add_analytics_report_menu_item()`**, subscribed to `woocommerce_analytics_report_menu_items` in `add_hooks()`. It appends
  `{ id: 'woodev-shipping-delivery', title: «Доставка перевозчиками», parent: 'woocommerce-analytics', path: '/analytics/woodev-delivery', capability }`
  **only under the orders page's own gate:** `has_providers()` (what `register_page()` checks) and `current_user_can( get_page_capability() )` (what the REST route
  checks and what `register_page()` passes as the page's capability). No new gate was invented. A non-array input is handed back untouched.
- **Enqueue on the report screen needed no change.** `Analytics::register_pages()` (on `admin_menu`) passes every entry of that filter to `wc_admin_register_page()`
  (default `js_page => true`), so the report is a registered `wc-admin` page and `Orders_Registry::is_wc_admin_screen()` (`wc_admin_is_registered_page()`) is true on it;
  `enqueue_assets()` therefore loads the page bundle there. Verified read-only on the dev rig: with the filter added ad hoc, `get_report_pages()` returns the entry and
  `PageController::get_pages()` holds it with `js_page = true` and the capability. The entry is registered at `admin_menu` priority 10, after our filter is subscribed.
- **JS — one bundle.** `index.tsx` adds `addFilter( 'woocommerce_admin_reports_list', 'woodev/shipping-delivery-report', addDeliveryReport )` beside the
  orders page's own `woocommerce_admin_pages_list` registration. `analytics/report-registration.ts` appends `{ report: 'woodev-delivery', title, component, navArgs: { id } }`
  (WooCommerce wraps the component with its scheduled-import notice and applies the filter in `useFilters`, so a later `addFilter` re-renders).
- **Shared body, no copy.** `AnalyticsTab` takes a `path` (default: the orders page's); `analytics/delivery-report.tsx` is `<AnalyticsTab path="/analytics/woodev-delivery" />`.
  `Filters`, `ChartPanel` and the tile selection write `period` / `compare` / `after` / `before` / `chart` / `interval` / `chartType` / `carrier` to the path they are on — the query
  WooCommerce's own reports use. Period and comparison come from `@woocommerce/date`'s `getCurrentDates()` exactly as in the tab (the tab's default, month vs previous period, applies
  when the URL carries none).
- **Not done / known:** the report has no WooCommerce `ReportFilters` wrapper (the tab's own filter row is used, so the two surfaces look and behave the same); the
  report is not added to WooCommerce's Analytics overview/dashboard sections; no screenshot of the mounted report (bundles are built by the coordinator).

## 7. Test plan

**PHPUnit (unit, Brain Monkey — no DB):**
- `AnalyticsQueryTest` — the SQL builder: HPOS vs legacy table/column selection (flip the `$hpos` seam as `Orders_Query` does); one leg per provider; «first provider wins» `NOT EXISTS`; `CASE` built from `invert_status_map` (including a provider with no status key → literal `unknown`, a map with an empty bucket → clause omitted); excluded statuses bound; date bounds are half-open and bound; every user value goes through `prepare()` (assert placeholders, no raw interpolation); the previous period reuses the same shape.
- `AnalyticsAggregatorTest` — pure PHP over fixture rows: buckets → `resolved`, shares null-safety, deltas (previous 0 → null), week/month roll-up with `start_of_week` Monday and Sunday, interval auto-pick, the time-to-delivery bound and `time_sample`.
- `AnalyticsControllerTest` (stubbed `WP_REST_Controller`, the repo's existing stub) — 403 without the capability, 400 on bad date / reversed range / range too wide / unknown carrier / half a compare pair, cache hit skips the query object, `refresh=1` bypasses, response shape keys, version bump debounced (two events in 60 s → one write).
- `AnalyticsCacheInvalidationTest` — hook wiring: the four actions bump once.

**Integration** (DB-backed, the existing `tests/integration/Shipping` pattern; the shared-DB suite does not run overnight — written, run by CI): seed orders across two providers, a multi-marker order, a cancelled shipment, an order with no status; assert the carrier table, **parity with `GET /shipping/orders`** (`total` equals `orders` for the same `after`/`before`/scope on both HPOS and legacy), and `carrier=` restriction.

**Jest (`src/shipping-orders-page/analytics/__tests__`):** tab switch via URL (`tab=analytics` ⇄ list, list filters cleared); `use-analytics` request building (`compare_*` from `getCurrentDates`), loading/empty/error states, tile → chart-metric selection, null ratios render «—», currency formatting through a mocked `CurrencyFactory`; `wc-globals` mocks for `Chart`/`SummaryList`/`DateRangeFilterPicker`. Gates: `npm run test:js` (bash, not PowerShell), `typecheck`, `lint:ts-baseline`, `lint:js-i18n`, `lint:mo`.

**Rig acceptance (functional is the coordinator's):** open the tab on the rig (HPOS, three carrier plugins active, 634 orders), compare the totals to `wp eval` read-only counts, check the day-edge parity, and the 50 000-order measurement of §4.3 on port 8974.

## 8. Open forks (provisional choices — the operator is asleep; none blocks)

| # | Fork | Provisional choice | Why / what flips it |
|---|---|---|---|
| F1 | Carrier cost vs shipping paid in v1 | **OUT** (§3) | no carrier stores a defensible «what we pay»; CDEK's is of unstated ownership. Flips if a carrier states its contract cost as its own `Shipment_Facts` component. |
| F2 | Handover → delivery time | **v1 ships the order-to-delivery substitute** (§2.4) with a coverage count; the exact metric needs a **forward-only status log** — one choke point, `Delivery_Status_Events::notify()` (fires once per REAL change): append `[ state, time ]` to new meta `_woodev_delivery_status_log_{provider}` (cap 20). NOT implemented tonight (decision 1: only stored data, and a new meta contract is a framework change). | A card to be filed in the morning (the log only becomes useful over time, so earlier is better). If he wants the exact metric, it is a ~20-line additive commit. |
| F3 | «Refused» vs «returned» | one bucket **Отказ / возврат** (`returning` + `returned`), `failed` separate «Не доставлено» (§2.3) | the canonical set has no buyer-refusal state; the carriers map refusals into the return states and mix export errors into `failed`. |
| F4 | Default period | **last 30 days, compare = previous period** | WC's own report default is `month` vs `previous_year`; a delivery-quality view reads better against the immediately preceding window. Pure default — the URL carries the choice. |
| F5 | Day buckets and DST | one fixed site UTC offset per request | exact bucketing needs `CONVERT_TZ` tables that shared hosting often lacks; error ≤ 1 h of orders on a DST-change day. |
| F6 | If the cold query misses the bar on 50 000 orders | nightly Action-Scheduler materialisation into a small summary table | only if the §4.3 measurement fails; adds a table and a data contract, so not built speculatively. |
| F7 | Refunds | **not netted** from revenue | WC Analytics nets them, but a per-order refund sum needs another correlated subquery per leg; v1 labels revenue «по сумме заказов». Easy add-on later. |
| F8 | Excluded WC statuses | `pending, failed, cancelled, refunded, checkout-draft, auto-draft, trash`, filterable | `on-hold` is kept (paid-by-transfer shops live there); flips to match whatever the operator's reports expect. |
| F9 | Multi-currency | not converted; one `currency` per response | out of the framework's market. |

## 9. Implementation notes — where the code diverged from §1–§8 (stage 2)

| # | Spec said | Implemented | Why |
|---|---|---|---|
| 1 | Shipping line price = itemmeta `total` + `total_tax` (§1.2, §2.2, §4.1) | `cost` + `total_tax` | Measured on the rig: a shipping item stores `method_id`, `instance_id`, `cost`, `total_tax`, `taxes`; there is no `total`. |
| 2 | A method row belongs to the carrier whose `get_method_ids()` contains it (§2.2) | Grouped by (the ORDER's carrier, `method_id`) | One statement, no second lookup; a method id seen under two carriers is two rows, which is what the merchant sees. |
| 3 | Default period `last_30_days` vs previous period (§5, F4) | `period=month&compare=previous_period` | `@woocommerce/date` has no such preset (measured: today, yesterday, week, month, quarter, year and their `last_*`, custom); `getCurrentDates()` throws on an unknown period. |
| 4 | Chart: `time-comparison` for one carrier (§5) | Always `item-comparison`, one series per carrier, no previous-period overlay | The `time-comparison` data shape is not documented in the README; the previous period is shown on the KPI tiles (value, previous value, change). The server still returns `previous_intervals`. |
| 5 | Money via `@woocommerce/currency` (§5) | `Intl.NumberFormat('ru-RU')` in `analytics/format.ts` | No runtime dependency to measure per WooCommerce version; `window.wc.currency` is a module object, not a callable (see the note in `wc-globals.d.ts`). |
| 6 | Tab module imported lazily (§5) | Plain import; `page-tabs.tsx` wraps `OrdersPage` and `AnalyticsTab` | `React.lazy` is not usable in the wc-admin bundle context. `index.tsx` registers `PageTabs`; `app.tsx` changed only by removing `RoiPanel`. |
| 7 | Methods for both periods | Methods are read for the current period only | The table has no comparison; saves a statement. Per request: 3 statements current + 2 previous (carrier, day). |
| 8 | Cache invalidation on four events (§4.2) | Registered by `Orders_Registry::add_hooks()` through `Analytics_Cache::register()` | Same as spec; named here because it is the one place existing code was touched on the PHP side (plus `register_rest()`, `class-map.php`, `Shipping_Plugin::includes()`). |
| 9 | Chart header controls | `interval` / `chartType` written to the URL by `Chart` itself (`path`, `query` props) and read back by the tab | WooCommerce's own mechanism. |
| 10 | — (not in the spec) | Revenue per shipping method: an inner statement collapses an order's lines of one method into one row (`MAX(total)`), the outer sums the orders | Acceptance item 1 of the card; the first version of §2.2 dropped it. |
| 11 | Chart header controls write `type` | They write **`chartType`** (read off the installed bundle: `handleTypeToggle` → `updateQueryString({ chartType })`); the tab reads that key | Round-1 finding 2. |
| 12 | — | `DateRangeFilterPicker` is keyed by its effective date query; `Chart` is keyed by (metric, answer, period, carrier filter, carriers) so it remounts | The installed components copy their props into state once (`getResetState()` in the constructor; legend `visibleKeys` rebuilt only when the SET of series keys changes). Round-1 findings 3 and 4. |
| 13 | Values bound through numbered placeholders `%N$s` (§4.1) | A string is bound as `'%N$s'` — **quoted in the template** — and every use of a bound value is its own argument; `run()` throws on a database error and the route answers a generic 500 that is never cached | Rig smoke: `wpdb::prepare()` inserts a numbered placeholder UNQUOTED (so every uncached request failed on a date) and raises a `_doing_it_wrong` notice when one numbered placeholder is used twice; an error returned as `[]` was cached as «no orders». Verified through the REAL `$wpdb->prepare()` on the rig: Oct 1–31 = 26 orders, Jul 1–Sep 30 = 88, Jun = 13 (carrier, day and method statements all agree). |
| 14 | Three outer statements: carrier, carrier × day, method (§4.1) | **Two**: carrier × day (it carries every count, so the carrier table and the totals are those rows summed in PHP, `Analytics_Aggregator::carrier_rows()`) and method, whose legs are SLIM (carrier, order id, total) and which is `STRAIGHT_JOIN`ed so the population drives and `woocommerce_order_items` is probed by `order_id` | Perf round (50 000 orders): the legs are ~0.7 s each pass, so a full report that ran them five times cost ~4.3 s for a year. Now three passes ≈ 2.5 s. Left alone, the optimizer drove the method statement from a scan of the itemmeta table for a year or a quarter. No index is added to a WooCommerce core table. |
| 15 | The JS report registers whenever the bundle loads (§6) | It registers only when `window.woodevShippingOrders.analyticsReport === true`, computed by `Orders_Registry::analytics_report_allowed()` — the SAME predicate as the menu entry | Critic round 3: WooCommerce's generic `/analytics/:report` route needs only `view_woocommerce_reports`, so a role without the page capability could reach the report by an in-app link and get a 403 screen. |
| 16 | Cache key = request + data version (§4.2) | + `Analytics_Cache::SCHEMA_VERSION` (bump when the SQL or the response shape changes) | Rig smoke round 3: an answer computed by an older build survived a deploy for up to an hour (a zero from the broken round-1 SQL was still served for Q3). |
| 17 | Period from `getCurrentDates()` (§5) | A URL with a `period` but no `compare` is completed with `previous_period` first (`withComparison()`) | `getDateParamsFromQuery()` honours a query only with BOTH keys and otherwise returns the default range, so `period=custom&after&before` was silently replaced by «this month». Same resolver in the tab and in the report. |
| 18 | F2 «A card to be filed … ~20-line additive commit» — `[ state, time ]` array in `_woodev_delivery_status_log_{provider}` (cap 20), exact metric later | Built as card #1564, three differences (a)–(c), then two more after the critic (d)–(e). (a) **JSON string, not an array**: SQL cannot average a field inside one meta value, so the analytics statement (`Analytics_Query::handover_sql()`) returns the raw log of each DELIVERED order that has one and PHP parses it with `Delivery_Status_Log::decode()` — a loop over those logs (delivered orders logged since recording began), not over orders; a serialised array would force `unserialize()` on a raw column. (b) **A separate class** `Delivery_Status_Log` (stage / read / decode / `handover_to_delivery`) called from `notify()` instead of ~20 lines inside it, so the writer and the analytics share ONE definition. (c) **Cap rule**: the newest 20 are kept, and a log AT the cap is not used for the metric (its first handover may be gone) — a missing figure instead of a wrong one. (d) **Handover, revised after the Codex critic (fix round 1)** = the first entry that is `in_transit` OR whose RAW code the provider declared in `handover_statuses` (each entry now stores `[ state, time, raw ]`); `ready_for_pickup` never starts the interval (destination arrival), and a log with no handover entry is not counted. A declared raw code earns one extra entry even when the canonical state is unchanged (CDEK maps the warehouse receipt to `created`). delivered = first `delivered` after it; bound 0 … 180 days. (e) **The write is serialised**: `Delivery_Status_Events::reconcile()` takes `Order_Lock` (scope `status`), re-reads the order, decides baseline + log entry together and saves once; a refused lock or failed save records nothing, so the change retries — the first version appended from a stale snapshot and could erase a real transition. The REST answer gains `avg_days_handover_to_delivery`, `handover_sample` (metrics) and `coverage.handover_sample` (additive); `Analytics_Cache::SCHEMA_VERSION` 1 → 2. The tile «От заказа до вручения» is unchanged while `handover_sample` = 0; above 0 it becomes «От передачи до вручения» with «по N из M доставленных». Cost: one more statement per period (legs scanned once more, delivered rows only) — to be measured with the open 50 000-order run (§4.3). |

**Rig measurement before the perf round (HPOS, WC 11.2.1, 634 orders / ~700 marker rows, five providers, MariaDB):** server-side 4.2–4.8 ms per statement for the carrier and day statements,
6.1–6.7 ms for the method statement, repeated runs identical (the first run is no slower: the data fits in the buffer pool). One report = 3 + 2 statements ≈ 25 ms cold.
Results were cross-checked against independent counts (189 realistic orders, 33 delivered). `EXPLAIN`: the driver uses `meta_key_value`, the order join is by `PRIMARY`, the
operational-data join by `order_id`, the correlated lookups by `order_id_meta_key_meta_value` (with a tiny filesort on `ORDER BY id DESC LIMIT 1`). Two legs showed the
status lookup on `meta_key_value` instead — a small-table optimiser choice; whether it flips at scale is exactly what the 50 000-order measurement (§4.3, still open, a card) must answer.
The legacy-datastore statement was run for syntax only (the rig is HPOS); its parity with HPOS is pinned by the unit tests and must be confirmed on a legacy install.

## Related

- [orders-page contract facts](../wiki/architecture.md#shipping-orders-page--the-contract-facts-that-outlive-their-cards) — page registration, `window.wc.*`, marker contract.
- [SP-10 orders page design](2026-09-07-sp10-orders-page-design.md) — page frame (D1/D7), filters (D10) and date range (D11) this tab builds on.
- [DOCS-SCHEMA.md](../DOCS-SCHEMA.md) — spec format and language rule.
- Gotchas: `narrowing-the-id-query-by-a-date-window-only-pays-below-a-few-percent-of-the-store`, `wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry`, `wc-date-throws-on-a-half-filled-custom-range`, `declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle`, `local-npm-run-build-is-not-assets-parity-evidence`, `the-orders-filter-stands-on-two-unenforced-carrier-invariants` (all under [`../gotchas/`](../gotchas/)).
- Card #711 (GitHub) and the s211 overnight plan: [sessions/s211.md](../sessions/s211.md).
