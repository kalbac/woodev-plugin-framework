# Gotcha: [shipping/cdek] — CDEK accepts an order to an office that cannot take money and moves it, with only a warning

> Tags: cdek, pickup, cod, delivery_recipient_cost, export | Card: #1475 (research, 26.11.2026)

## What happens

`POST /v2/orders` (production API, tariff 136) to office `SPB1280`, which has `allowed_cod: false`, with
`delivery_recipient_cost` 300 and items `payment.value` 0: the request settles as `SUCCESSFUL`, and the only trace is
`requests[].warnings[]` — `v2_delivery_point_changed`, «Delivery point has been changed to SPB48». The same order to
the same office with a zero fee is accepted unchanged. Measured once per case.

## Root cause

A delivery fee to collect is money from the recipient, like a cash-on-delivery amount. CDEK does not refuse the order;
it substitutes another office. A caller that reads only `state` and `errors` believes the chosen office was kept.

## Correct

```php
// ❌ state SUCCESSFUL and no errors → "exported as asked"
// ✅ also read requests[].warnings[]; on v2_delivery_point_changed tell the merchant, and never offer an office
//    without allowed_cod when anything (goods or delivery) is collected on receipt — not only for the `cod` gateway
```

Today the CDEK plugin reads no order warnings and blocks such offices only for the `cod` payment method.

## Related

- [Research: carriers](../research/2026-11-26-pay-delivery-on-receipt/carriers.md) — all six probe cases
- [Probe script](../research/2026-11-26-pay-delivery-on-receipt/probe_cdek.py)
