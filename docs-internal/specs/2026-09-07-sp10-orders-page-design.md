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
- **The `type` column is derivable by the framework.** Yandex renders «До ПВЗ / До двери» from
  `is_pickup()`; russian-post renders «Курьер / Постамат / Отделение». The framework already ships
  `Shipping_Method_Courier`, `Shipping_Method_Pickup` and `Shipping_Method_Postal`, so this is a
  framework column computed from the order's shipping method, not a carrier column.

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

**Decision:** a `Shipping_Orders_Registry` singleton, built as the exact structural mirror of
`Settings_Page_Registry` — one `add_submenu_page()` under the existing `woodev` top-level menu, slug
`woodev-shipping-orders`, registered only when at least one provider is present, capability resolved
the same way, React assets enqueued on its own hook.

**Why a mirror and not something new:** §16's original decision claimed to mirror §15 and did the
opposite, because §15's mirror did not exist yet. It exists now
(`class-settings-page-registry.php:430`). Building the second aggregator to the same shape is what
makes that claim true for the first time, and it means one pattern to learn, not two.

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

## D7. React, and why increment 1 does not depend on it

**Decision: React**, mirroring `src/settings-page/` — its own entry under `src/`, built into
`woodev/assets/build/`, fed by a REST controller, using the shared `src/components/` kit. §16's
narrative says React; every other framework admin surface (settings, licenses, plugins, setup
wizard) is already React; a fourth hand-rolled `WP_List_Table` would be the odd one out.

**But the registry, the descriptor, the scope query, the status enum and the REST row contract are
UI-agnostic.** They are increment 1 and are identical whichever way the shell is drawn. The visual
shell is where the operator's eye matters, and it is worth showing him something rather than asking
him to imagine it — so that question is deferred until there is a page to look at, not asked up
front.

## Increments

1. **Registry + descriptor + scope query + REST rows.** No UI. Answers M2's open HPOS question by
   measurement.
2. **The page shell + the framework columns** (D3) with the canonical status (D4).
3. **Bulk actions** (D5).
4. **Menu counter and tab counts** (D6).
5. **Legacy slug redirect** (D1).

#710 (the «Создать заказ» modal) and #711 (ROI/charts) stay outside this spec — the first needs the
operator's brainstorm by his own instruction, the second is not a v1 goal.

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
