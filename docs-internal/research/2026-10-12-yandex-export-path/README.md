# Yandex export path: which call carries the delivery interval (v2)

**Status: BLOCKED at request creation. Steps 1–2 measured; steps 3–6 not run.**

## Status

- **Blocker.** The production (test) account refuses every call that creates a request. `offers/confirm` and
  `request/create` return HTTP 404 with `{"code": "invalid_payment_method", "message": "Ваш корпоративный счёт больше
  не активен"}` ("Your corporate account is no longer active"). This happened in all four cases (prepaid and COD
  alike) and on one retry about a minute later. The message is account-level. The same bodies are accepted by
  `offers/create`, so the body does not look like the cause, but this is not proven. The account owner needs to
  reactivate the corporate account before the paths can run.
- **Requests created: 0.** Nothing to cancel. `ledger.json` is `[]`. No non-cancelled `request_id` exists.
- **Measured:** steps 1 and 2 of the brief (offers/info vs offers/create intervals) for all four cases.
  Read-only calls only.
- **Not measured:** P1 (no interval), P2 (3rd and latest interval in `request/create`), P3 (`offers/confirm`
  of the 3rd offer), the assigned interval in `request/info`, and pickup-point interval behaviour. The three
  questions from the brief are therefore **not answered** by this run.
- **Re-run once the account is active:** `python3 measure_export.py paths` runs all four cases. It creates 16
  requests (4 per case, cap 20). Each request is cancelled and verified in the same step, and the ledger is
  written before the next call. `python3 measure_export.py cancel` retries any id that is not verified cancelled.

## Method

- Script: `measure_export.py`. It reuses the body builders of the previous measurement
  (`../2026-10-12-yandex-price-measurement/measure_price.py`, imported at runtime for `offers_body`, `calc_body`,
  `with_real_dims`, `resolve`, the token reader and the throttle constants). It has no token literals.
- Calls: `offers/info` (checkout shape: flat `source` and `destination`, `places` with real dims, as v1 checkout
  builds it), `offers/create` (v1 body, places carry real `dx`/`dy`/`dz` as the v2 rule requires, `info.referral_source`
  = `woodev`), `pricing-calculator` (COD only, to fill `billing_info.delivery_cost`), `offers/confirm` and
  `request/create` (both refused). Total calls: 27.
- Source: Moscow drop-off point Трубная площадь 2 (`20cfe81c-…`), as in the previous measurement.
- Parcel: medium, one place, 3000 g, 40 x 20 x 30 cm (`dx` = length 40, `dy` = height 20, `dz` = width 30).
  Cart subtotal 3000 RUB. Recipient data is synthetic.
- Destinations: C1 Moscow market_l4g pickup point (`05212278-…`), prepaid. C2 Samara market_l4g pickup point
  (`01a0ece7-…`), prepaid. C3 Samara courier address (`Россия, Самарская область, Самара, улица Куйбышева, 90`),
  prepaid. C4 Moscow market_l4g pickup point, COD (`card_on_receipt`).
- Latency: wall-clock per HTTP call, one sample each, so single-run noise applies.
- Raw: `raw/<case>/` holds every request body and response (no headers), including the refused calls.

## Results, steps 1–2: offers/info vs offers/create

| Case | Destination | offers/info intervals | offers/create offers | Same interval set | Price (every offer) | offers/info ms | offers/create ms |
|---|---|---|---|---|---|---|---|
| C1 | Moscow pickup, prepaid | 5 (15–19 Oct, 07:00–15:00 UTC) | 5 | yes | 252.54 RUB | 216 | 389 |
| C2 | Samara pickup, prepaid | 5 (17–21 Oct, 05:00–14:00 UTC) | 5 | yes | 466.04 RUB | 168 | 176 |
| C3 | Samara courier, prepaid | 11 (17–27 Oct, 06:00–19:00 UTC) | 11 | yes | 689.30 RUB | 787 | 243 |
| C4 | Moscow pickup, COD | 5 (15–19 Oct, 07:00–15:00 UTC) | 5 | yes | 354.56 RUB | 133 | 195 |

Facts from these runs:

- In all four cases the set of `(from, to)` pairs from `offers/info` equals the set of
  `(delivery_interval.min, delivery_interval.max)` pairs from `offers/create`. Both lists are in ascending time order.
- Within one case every offer has the same `pricing_total`. Offers differ only in `delivery_interval`, `offer_id`
  and `pickup_interval`.
- `expires_at` is 15 minutes after the call in all four cases (`expires_at` minus `pickup_interval.min`).
- `policy` is `self_pickup` for the pickup points and `time_interval` for the courier address.
- `offer_id` values are unique within each list.
- `offers/info` returns no price, only intervals.

## Relevant v1 behaviour (read from source, not measured)

- `create_offers()` removes `destination.interval_utc` before `offers/create`, so v1 offers never carry the
  customer's interval (`includes/class-order.php:345-347`).
- `prepare_order_data()` adds `destination.interval_utc` only when `show_time_intervals()` is on and both
  bounds are stored (`includes/class-order.php:191-196`). That body is what `create_request()` sends to
  `request/create` (`includes/class-order.php:317`).
- The manual path is `offers/create` followed by `offers/confirm` on the offer the admin picks
  (`includes/class-ajax.php:346`).
- The checkout interval list comes from `offers/info` with the checkout body (`includes/functions.php:234-265`,
  `includes/class-checkout.php:405-435`).

## Answers to the three questions

Not answerable from this run. What the run does establish: `offers/create` lists exactly the intervals that
`offers/info` shows, in all four cases. So the offer list gives the customer the same choices as checkout.
Whether a request booked with a chosen interval gets that interval, with or without an offer, is untested.

- (a) No interval: is the offer step needed? **Unknown** (P1 not run).
- (b) Customer-chosen interval: is the offer step needed? **Unknown** (P2 and P3 not run).
- (c) Latest interval: is the offer step needed? **Unknown** (P2 latest not run).

## UNVERIFIED

- Whether `request/create` honours `destination.interval_utc` (earliest, 3rd, latest), and what `request/info`
  shows as the assigned interval.
- Whether `offers/confirm` books the chosen offer's interval and price.
- Whether `request/create` price equals the offer price.
- Whether `interval_utc` applies at all to pickup points (`self_pickup`), and what date `request/info` shows as
  the arrival at the point.
- Whether `request/cancel` succeeds on these requests (not exercised, because no request was created).
- Whether the corporate-account block is caused by something in the request bodies (not proven, see Status).
