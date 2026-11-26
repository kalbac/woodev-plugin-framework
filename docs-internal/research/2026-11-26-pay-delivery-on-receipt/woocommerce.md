# WooCommerce: an order paid online for the goods only

Card #1475, question 3. WooCommerce paths and lines are **10.5.2** source (the dev-dependency version); framework paths
are `157c4bd9`. Everything here is read from code; nothing was run on a shop.

## What core allows

| Topic | Core behaviour | Where |
|---|---|---|
| Partial payment | none: `payment_complete()` sets `date_paid` and `processing`/`completed`, whatever was charged | `includes/class-wc-order.php:137` |
| Needs payment | status pending/failed **and total > 0** | `class-wc-order.php:1804`, `class-wc-cart.php:1503` |
| Refunds | capped at order total minus refunded | `wc-order-functions.php:583`, `class-wc-order.php:2423` |
| Analytics | `total_sales` = order total, `shipping_total` = order shipping, net = total − tax − shipping | `src/Admin/API/Reports/Orders/Stats/DataStore.php:551`, `:676` |
| Negative fee | capped so the total stays ≥ 0; its tax is spread over the tax classes of items, fees and shipping by cost, not tied to the delivery line | `class-wc-cart-totals.php:246`, `:287` |
| Grand-total filter | `woocommerce_calculated_total` changes the total without any line | `class-wc-cart-totals.php:883` |
| Rate filter | `woocommerce_package_rates` runs on recalculation, before the rates are cached in the session | `class-wc-shipping.php:395`, `:404` |
| Rate meta | copied onto the order's shipping line | `class-wc-checkout.php:640` |
| Zero-cost rate in classic checkout | label without a price (no «Free!») | `wc-cart-functions.php:380` |
| Zero shipping on an order | shows the method name only; «Free!» only without a method name | `abstract-wc-order.php:2250` |
| Totals rows (emails, thank-you, account, pay page) | `woocommerce_get_order_item_totals` | `class-wc-order.php:2510` |
| Checkout retry | classic: resumed only if the cart hash (contents and total) matches, else a new order; items are rebuilt, order meta stays. Store API: the draft order is reused | `class-wc-checkout.php:401`, [design](design.md#retries-and-the-order-meta) |
| Pay-for-order page | sets the chosen method and pays; **shipping is not recalculated** | `class-wc-form-handler.php:456` |

Block checkout may word a zero-cost rate as free — UNVERIFIED.

## Four ways to represent it

| | A. Total keeps delivery, gateway charges less | **B. Rate cost 0, amount in meta (recommended)** | C. Negative fee | D. Filter the grand total |
|---|---|---|---|---|
| Order total = money charged | no | **yes** | yes | yes |
| Lines add up to the total | yes | **yes** | yes, with a negative line | **no** |
| Statuses, `payment_complete` | «paid» overstates | **native** | native | native |
| Refunds | can refund money never taken | **capped correctly** | capped | breaks item refunds |
| Analytics | delivery counted as paid revenue | **delivery revenue 0** | shipping revenue plus a negative sales fee | inconsistent |
| Taxes | VAT on delivery stays on the order | **none on the order** | need not cancel the delivery VAT exactly | wrong |
| Item lists for banks (kopeck-exact, T-Bank D12) | need a special case | **natural** | negative line impossible | impossible |
| Carrier export today | unchanged | must read the meta | must read the meta | must read the meta |
| Pay-page switch of gateway | charge rule must follow the order | needs a guard | needs a guard | needs a guard |

B is what core already models: a delivery option that costs the shop's checkout nothing. Whether «no VAT and no
delivery revenue on the WooCommerce order» is the correct accounting is card question 1 — for the accountant.

## Consequences of B that need code

- **Amount.** Take the final rate cost **and its tax** at a very late `woocommerce_package_rates` priority (after any
  third-party price filter), store them in rate meta, then zero cost and taxes.
- **Recalculation on a payment change.** The packages must carry the chosen method — `Fee_Payments` already does this
  for classic and block checkout (`woodev/shipping-method/class-fee-payments.php`).
- **Buyer texts.** The notice at checkout; a totals row «due on receipt» in emails, thank-you and account pages.
- **Zero total.** Goods fully discounted → cart total 0 → no payment step, while the session still remembers the
  gateway. The mode must not apply then, and the order creation must re-check the order's own payment method.
- **Free delivery** (threshold, coupon): amount 0 → the mode simply does not apply.
- **Pay-for-order.** For an order that carries the meta, offer only gateways with the switch on (or the buyer pays
  goods only and nobody collects delivery). A cash-on-delivery switch there would otherwise collect goods with
  shipping 0.
- **Checkout retries.** The lines are rebuilt, but order meta survives: the amount must live on the shipping line,
  with any order copy rewritten or deleted on each build ([design](design.md#retries-and-the-order-meta)).
- **Merchant edits.** Changing the method, items or shipping in admin does not recompute the meta; export must show the
  amount and refuse a mismatch.
- **Refunds.** Only the goods can be refunded in WooCommerce. A delivery fee the carrier already collected is returned
  outside the order, by hand.

## The framework payment base

- `Woodev_Payment_Gateway::get_order()` sets `payment_total` = order total and says it may be altered
  (`woodev/payment-gateway/class-payment-gateway.php:1478`, filter `wc_payment_gateway_{id}_get_order_base`). Option A
  is therefore possible without hacks — and still rejected for the reasons above. Under B nothing changes here.
- `process_refund()` marks the order refunded when total equals refunded (`:1652`) — correct under B.
- Capture uses order total minus captured — correct under B.
- Nothing in the payment module knows about shipping today; the switch is the only new thing it needs.

## Framework shipping code that reads the shipping total

| Place | Effect of B | Change |
|---|---|---|
| `Carrier_Order_Lines::order_lines_target()` (`order/class-carrier-order-lines.php`) | subtracts shipping, 0 here — correct | none |
| `Analytics_Query`, «paid by the buyer» (`admin/orders/class-analytics-query.php:543`) | shows 0 → delivery looks loss-making | add the due amount, labelled |
| `Order_Row_Builder` (`admin/orders/class-order-row-builder.php:528`) | shows 0 ₽ | show «X ₽ при получении» |
| Carrier plugins' export and cash-on-delivery tests | see [carriers.md](carriers.md) | read the meta |

## Related

- [Research overview](README.md)
- [Design](design.md)
