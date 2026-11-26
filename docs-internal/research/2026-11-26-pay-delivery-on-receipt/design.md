# Design sketch: delivery paid on receipt

Card #1475. A sketch to be specified after the [owner questions](owner-questions.ru.md), not a spec. Names are
proposals. Based on [WooCommerce option B](woocommerce.md).

## Flow

```text
buyer picks a gateway with the switch ON ──► packages carry payment mode "delivery_on_receipt"
                                              │  (only for methods that say they can collect)
carrier method quotes in that mode ──────────► insurance / commission / points as for collection
late woocommerce_package_rates ──────────────► meta {amount, tax, currency}; cost = 0; label «… — при получении»
checkout ────────────────────────────────────► notice «При получении нужно будет оплатить {сумма доставки}»
order ───────────────────────────────────────► total = goods; shipping line + order meta keep the amount
export (carrier plugin) ─────────────────────► goods prepaid, recipient pays <amount>
```

## Seams

| Seam | Where | Shape |
|---|---|---|
| Shared core | new neutral class in `woodev/`, used by both modules, static and fleet-wide like `Fee_Payments` | which gateways have the switch (stored registry readable before methods register); the mode for a package; `due( WC_Order )` |
| Carrier capability | `Shipping_Method` | `FEATURE_DELIVERY_ON_RECEIPT` plus `can_collect_delivery_on_receipt( array $package ): bool`. Off by default, so an old carrier plugin under a newer framework stays unaffected |
| Payment mode | package and rate-cache key (`get_rate_cache_context()`, `class-shipping-method.php:3236`) | one answer — prepaid, cash on delivery, delivery on receipt — instead of today's `cod` boolean (`is_cod_gateway()`, `:3607`, filter `woodev_shipping_insurance_cod_gateways`) |
| Payment switch | `Woodev_Payment_Gateway` settings fields | checkbox and an editable text with `{сумма доставки}`; shown only when some active carrier method has the capability; a gateway may opt out |
| Recalculation | `Fee_Payments` | one more reason for `is_used()`: any gateway with the switch |
| Rate | late `woocommerce_package_rates` | move cost and taxes into `_woodev_delivery_on_receipt`, zero the rate, adjust the label |
| Checkout notice | framework, classic checkout | next to the payment methods, refreshed by `update_order_review`; block checkout later |
| Order | shipping line meta (copied by core) — the **single authority**; an order meta only as a derived copy for queries | amount incl. tax, tax, currency, gateway id. `due( $order )` reads the shipping lines only. The copy is rewritten **or deleted** on every order build, see [below](#retries-and-the-order-meta). **New installed-data contract — name it once** |
| Totals rows | `woocommerce_get_order_item_totals` | «К оплате при получении» in emails, thank-you, account |
| Point guards | `Constraint_Checker` (`pickup/class-constraint-checker.php:76`, built with `['cod']` by default in `Pickup_Controller` and `Pickup_Selection_Service`) and `woodev_shipping_available_payment_gateways` | the mode counts as collecting money: points without it are blocked with a reason |
| Later payment change | available gateways on the pay-for-order page | an order whose shipping line has the meta offers only gateways with the switch. Not at checkout: there the lines are rebuilt for the chosen gateway |
| Export | carrier plugin, reading `due( $order )` | send the amount; **refuse** with a merchant message when the shipment cannot collect it (method changed, country, courier, point); surface the carrier's warnings |
| Admin | orders page, order panel, analytics | see the table in [woocommerce.md](woocommerce.md) |

Licence: no new rule — a carrier without a licence offers no rate and a gateway without one is not offered; export and
callbacks follow the existing gate.

Several packages: the mode applies per shipping line; the notice shows the sum of the lines it applied to.

## Retries and the order meta

WooCommerce 10.5.2, read from code:

- **Classic checkout** resumes the pending or failed order in `order_awaiting_payment` only while the cart hash
  (contents **and total**) matches (`class-wc-checkout.php:401`); otherwise it creates a new order. A resume deletes
  items and their meta (`:413`), **not order meta**. Switching the mode changes the total, so it normally yields a new
  order, but an unchanged total (another gateway with the switch, an offsetting fee or hash filter) resumes the old one.
- **Block checkout** reuses the draft order (`StoreApi/Utilities/DraftOrderTrait.php`, `is_valid_draft_order()`: any
  `checkout-draft`; pending or failed while the cart hash matches) and rebuilds the shipping lines when the chosen
  rates, their cost or meta included, change (`OrderController.php:813`, `CartController::get_cart_hashes()`).
  **Order meta survives.**

So a copy left in order meta can outlive its shipping line. If `due()` read it, the export would collect delivery that
was already paid online — the buyer pays twice; the orders page and analytics would show an amount that does not
exist. Rules:

1. The shipping line meta is the only source of the amount. Export, guards and buyer texts read nothing else.
2. On every build, derive the order copy from the lines, deleting it when no line has the meta: classic
   `woocommerce_checkout_create_order` (after the lines, new and resumed orders alike), Store API
   `woocommerce_store_api_checkout_order_processed` (after the final payment method, before payment). Admin edits of
   shipping lines resync it too.
3. At the same point, check that a line's gateway id equals the order's payment method; on a mismatch stop the order
   and recalculate rather than save it.
4. An abandoned pending order keeps its meta and stays payable by link — the pay-for-order guard covers it.

## Per carrier

| Carrier | Capability true when | Export |
|---|---|---|
| CDEK | shop contract (`type` 1), not to Belarus, order currency = recipient currency | `delivery_recipient_cost` = amount with the delivery VAT setting; items `payment.value` 0; check `v2_delivery_point_changed` |
| Russian Post | ЕСПП present, product has a cash-on-delivery variant, not a postamat, domestic | category with cash on delivery; `payment` = amount; `insr-value` = max(goods, amount) |
| Yandex | after the spike: pickup method, point takes payment | `card_on_receipt`; every `unit_price` 0, `assessed_unit_price` **stays the goods value** (the declared value, as in probe y2); `delivery_cost` = amount |
| Ozon Delivery | never | — |

## Card plan (rough)

| # | Card | Size | Depends on |
|---|---|---|---|
| 0 | Accountant / contract answers (card questions 1 and 4) and owner decisions | — | owner |
| 1 | Framework core: shared class, capability, payment switch, payment mode, rate rewrite, order meta | L | 0 |
| 2 | Framework buyer side: notice, totals rows, pay-for-order guard, zero-total guard. Acceptance includes: fail a payment, switch to a gateway without the switch (same and changed total, classic and block) — no order meta left, export collects nothing | M | 1 |
| 3 | Framework guards and admin: point checker, gateway filter, orders page, analytics | M | 1 |
| 4 | CDEK plugin: capability, export, currency and Belarus rules, point-changed warning | M | 1–3 |
| 5 | First payment plugin enables the switch (T-Bank line, #1474) | S | 1, 2 |
| 6 | Live acceptance: one real CDEK parcel — collection, receipt, payout registry | S, owner's hands | 4 |
| 7 | Russian Post plugin | M | 1–3 |
| 8 | Yandex spike: confirm a request with zero prices on a test account, ask Yandex about receipts and permission | S | 0 |
| 9 | Yandex plugin, only if 8 passes | M | 8 |
| 10 | Public docs and plugin-author skills | S | 4 |

Release gate suggestion: cards 1–6 (CDEK), then 7, then 8–9.

The CDEK point-changed warning is worth fixing even without this feature: any order CDEK cannot serve at the chosen
office may be moved while the shop believes otherwise (measured only for the collection case).

## Related

- [Research overview](README.md)
- [Carriers](carriers.md)
- [WooCommerce](woocommerce.md)
