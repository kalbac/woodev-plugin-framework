# Carriers: can the recipient pay only delivery on a prepaid order?

Card #1475, question 2. Labels: **[docs]** official text, accessed 26.11.2026; **[measured]** our probe, 26.11.2026
unless dated otherwise; **[code]** plugin or framework source; **UNVERIFIED**; **[inference]**.

## CDEK — supported

Source: official OpenAPI file,
[gateway.cdek.ru](https://gateway.cdek.ru/api-cdek-docs/web/docs/merged/file/openapi_api_v2_integration.json)
(portal: [apidoc.cdek.ru](https://apidoc.cdek.ru/)).

| Item | Fact |
|---|---|
| Endpoint | `POST /v2/orders`; change with `PATCH /v2/orders` only while the status is «Создан» [docs] |
| Field | `delivery_recipient_cost {value, vat_sum, vat_rate}` — «Доп. сбор за доставку, которую ИМ берет с получателя» [docs] |
| Goods prepaid | `packages[].items[].payment.value` = 0 («в случае предоплаты значение = 0») [docs] |
| Order type | `type: 1` («интернет-магазин», needs the shop contract) only [docs] |
| Ignored | Belarus→Belarus and Russia→Belarus directions [docs] |
| Limits | ≤ 50 000 000 in the recipient city's currency; VAT 0, 5, 7, 10, 16, 22 or none [docs] |
| Thresholds | `delivery_recipient_cost_adv` — fee by the redeemed goods sum, only with the «частичная доставка» service [docs] |
| Collected sum | `delivery_detail.payment_sum`, `payment_info` on the order [docs] |
| Payout | `GET /v2/registries`: `payment_sum`, `agent_commission_sum`, `transfer_sum` per order [docs]. That the delivery fee appears there is [inference] |
| Receipt | `GET /v2/check` returns the receipts; `seller` {name, inn, phone} puts the true seller on them, else the contract holder [docs] |
| Collection fee | UNVERIFIED (third-party sites say 3 % for companies) |

### Probe (`probe_cdek.py`, production API, tariff 136 office→office)

| Case | Declared `cost` | `payment` | Fee | Office takes cash on delivery | Result [measured] |
|---|---|---|---|---|---|
| c1 | 1000 | 0 | 300 | yes | `SUCCESSFUL`, fee stored |
| c2 control | 1000 | 0 | 0 | yes | `SUCCESSFUL` |
| c3 | 0 | 0 | 300 | yes | `SUCCESSFUL` |
| c4 | 100 | 0 | 300 | yes | `SUCCESSFUL` (fee above declared value is accepted at registration) |
| c5 | 1000 | 0 | 300 | **no** (SPB1280) | `SUCCESSFUL` + warning `v2_delivery_point_changed`: **moved to SPB48** |
| c6 control | 1000 | 0 | 0 | no (SPB1280) | `SUCCESSFUL`, office unchanged |

c5 against c6 (one office, one run each) says CDEK treats the fee as money to collect, and does **not refuse** — it
changes the office. The registration total (`total_sum` 314.15) is the same with and without the fee, so any collection
commission is charged later. Not tested: hand-over, collection, receipt, registry, parcel lockers, courier tariffs.

### Wiring today [code, `woocommerce-edostavka` `dccba61`]

- `includes/orders/class-cdek-order-request.php`, `delivery_recipient_cost()` (≈ line 998): the shipping total plus tax
  **only when the payment method is literally `cod`**, else 0. `item()` (≈ 852) sets `payment.value` by the same test.
- The same literal test drives `assert_currency()`, `insurance_forced_by_cod()` and
  `Cdek_Shipment_Handler::note_uncollected_delivery_cost()` (the Belarus note).
- Pickup points carry `accepts_cod` from `allowed_cod` (`class-cdek-pickup-point-mapper.php`); the framework
  `Constraint_Checker` blocks them for `cod` only.
- Nothing reads order `warnings` (no `v2_delivery_point_changed` anywhere).

So the export change is small: take the amount from the order's delivery-on-receipt meta, keep `payment.value` 0.

## Russian Post — supported, with limits

Sources: [cash on delivery](https://www.pochta.ru/support/post-rules/cash-on-delivery),
[cash on delivery for business](https://www.pochta.ru/support/corporate-services/nalozennyj-platez-dla-biznesa),
[COD service](https://www.pochta.ru/support/corporate-services/cod) (tariffs from 24.03.2026).

Two different services exist [docs]:

| | «Наложенный платёж» | «Услуга COD» |
|---|---|---|
| API category | `WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY`, field `payment` | the `…COMPULSORY_PAYMENT` categories, field `compulsory-payment` [inference from names] |
| Basis | postal money transfer | agency contract |
| Amount vs declared value | payment **may not exceed** it | declared value must **equal** the amount collected |
| Fee | 2.86 % (min 49 ₽) if the sender pays, 2.45 % (min 40.8 ₽) if the recipient pays; the recipient's fee is **added on top** | 2.45 % (min 40.8 ₽), charged to the shop; excl. VAT |
| Receipt to the recipient | for the **money transfer service** | for the **goods**, per the shop's data |
| Payout | within 3 working days, with a registry | 2 working days, with a registry |

For delivery-only collection the first one fits: the goods stay declared at their value and `payment` is the fee. Under
«Услуга COD» the declared value would have to drop to the fee, leaving the goods uninsured.

### Probe (`probe_pochta.py`, `PUT /2.0/user/backlog`, test cabinet) [measured]

| Case | Category | Values, ₽ | Result |
|---|---|---|---|
| p1 | `ONLINE_PARCEL` + cash on delivery | declared 5000, payment 300 | **created**, stored as sent; deleted, 404 |
| p2 | same | declared 100, payment 300 | `NOT_INSURED_PAYMENT` «Наложенный платеж превышает объявленную ценность» |
| p3 | same | declared 0, payment 300 | `ILLEGAL_INSR_VALUE` |
| p4 | 7 × `WITH_DECLARED_VALUE_AND_COMPULSORY_PAYMENT` | 300 | `RESTRICTED_MAIL_CATEGORY` (the text asks for an ЕСПП number although plain cash on delivery works on this account) |
| p4 | `ECOM` compulsory | 300 | `DISABLED_MAIL_TYPE` |
| p5, p6 | `WITH_COMPULSORY_PAYMENT` on parcels | 300 | `ILLEGAL_MAIL_CATEGORY` |

Limits: an ЕСПП number is needed for cash on delivery; parcel lockers refuse it (21.10.2026,
[live report](../2026-10-21-russian-post-v2/g-otpravka-live.md)); abroad only some countries take it and the plugin
turns it off. Batch, forms and hand-over of a fee-only order are UNVERIFIED.

### Wiring today [code, `woodev-russian-post` `4a0cad0`]

`includes/order/class-russian-post-order-export.php`: under cash on delivery `payment` = the **whole** remaining order
total and `insr-value` = the same. The variant comes from the method instance (`order_terms()`, its configured
gateways), fallback `cod`. The new mode is a third case: category with cash on delivery, `payment` = fee,
`insr-value` = max(declared goods value, fee). Quoting, point rules (postamats) and
`Russian_Post_Payment_Gateway_Filter` must treat it as cash on delivery. Insurance (about 1 % of the declared value,
[live report](../2026-10-21-russian-post-v2/g-otpravka-live.md)) becomes unavoidable.

## Yandex Delivery — workaround only, not confirmed

Sources: [create request](https://yandex.ru/support/delivery-profile/ru/api/other-day/ref/3.-Osnovnye-zaprosy/apib2bplatformrequestcreate-post),
[delivery](https://yandex.ru/support/delivery-profile/ru/other-day/delivery),
[payment](https://yandex.ru/support/delivery-profile/ru/other-day/pay),
[corporate terms](https://yandex.ru/legal/logistics_terms_of_use/ru/) (published 11.11.2026),
[offer](https://yandex.ru/legal/logistics_corporate_prepaid_offer/ru/) (14.10.2026).

| Item | Fact [docs] |
|---|---|
| Field | `billing_info.delivery_cost` (kopecks) — «Сумма, которую нужно взять с получателя за доставку… Актуально только для заказов с постоплатой» (`card_on_receipt`, `postpay`) |
| So | `already_paid` + `delivery_cost` collects **nothing** |
| Terms | the partner may not change the agreed payment form, «равно как и прием оплаты Партнером за предоплаченные отправления» (deferred delivery, §2.4) |
| Receipt | the partner issues a receipt «на стоимость отправлений и/или доставки» from the shop's data; the shop answers for cash-register duties, including «чеки на зачет предоплаты» (§4.2) |
| Money | Yandex collects in its own name on the shop's instruction (agency, GK ch. 52), transfers within 3 calendar days (§4.4); since August 2025 collected money first covers Yandex's service bill |
| Where | pay on receipt only at some Yandex Market pickup points; not at partner points, 5Post or parcel lockers; the help page says courier delivery is prepaid only, while the API enum names `postpay` «для курьерской доставки» — contradiction, treat the courier as unsupported |
| Partial redemption | `variable_delivery_cost_for_recipient` lowers the fee by the redeemed goods sum, pay-on-receipt only |

### Probe (`probe_yandex.py`, `offers/create`, production token, goods declared 3000 ₽, fee 300 ₽) [measured]

| Case | `payment_method` | item `unit_price` | `delivery_cost` | Offers | Collection commission |
|---|---|---|---|---|---|
| y1 | `already_paid` | 3000 | 300 | 5 | none (the field is accepted and ignored in pricing) |
| y2 | `card_on_receipt` | **0** | **300** | 5 | **9.15 ₽** (3.05 % of the fee only) |
| y3 | `card_on_receipt` | 0 | 0 | 5 | 0 |
| y4 control | `card_on_receipt` | 3000 | 300 | 5 | 100.65 ₽ |

UNVERIFIED: `offers/confirm` / `request/create` accepting zero prices, the pickup-point flow («получателю нужно
оплатить»), what the receipt shows, and whether Yandex permits declaring a prepaid order this way. A courier under
`card_on_receipt` was refused by the calculator in all 36 requests on 12.10.2026
([measurement](../2026-10-12-yandex-price-measurement/README.md)).

### Wiring today [code, `woocommerce-yandex-delivery` `d31b8c3`]

`includes/orders/class-yandex-order-request.php`: `payment_method` = `card_on_receipt` when
`Yandex_Rate_Rules::is_cod()` (filter `woodev_yandex_delivery_cod_payment_methods`), else `already_paid`;
`delivery_cost` = shipping line total plus tax under cash on delivery, else 0. `unit_price` carries the goods price in
**both** cases, so the new mode must send 0 explicitly — otherwise the buyer pays for the goods twice. Quote
(`Yandex_Pricing`, commission on the delivery amount), the courier hiding and `Yandex_Payment_Gateway_Filter` follow
the same `is_cod()` test.

## Ozon Delivery — not supported

[code, `woodev-ozon-delivery` `fdd80bc`] The saved API description
(`docs/reference/ozon-delivery-swagger-2026-10-30.json`: `/v1/order/checkout`, `/v1/order/create`, `/v1/posting/*`)
has no payment, cash-on-delivery or recipient-amount property; money fields are the declared value and estimated
delivery and insurance costs. The plugin's gap document says the same («no cash-on-delivery in this API»). Current
online reference: UNVERIFIED.

## Refusal on receipt (card question 4 — what the APIs and terms say only)

| Carrier | Fact |
|---|---|
| CDEK | A fixed fee: nothing in the docs says it is collected on a full refusal. With `delivery_recipient_cost_adv` and «частичная доставка» a fee can be taken on full refusal by adding a zero-priced item «Доставка» [docs]. `POST /v2/orders/{uuid}/refusal` registers a refusal and returns the parcel [docs]. Who pays the return: contract, UNVERIFIED |
| Russian Post | The recipient may refuse and not pay; the parcel returns to the sender [docs]. Return cost: UNVERIFIED |
| Yandex | Full refusal to pay = refusal of the purchase; Yandex may demand, and the shop must pay, the services rendered and the return [docs, §2.4] |

## Related

- [Research overview](README.md)
- [Design](design.md)
- [CDEK moves the delivery point](../../gotchas/cdek-moves-the-delivery-point-with-only-a-warning-when-money-is-to-be-collected.md)
