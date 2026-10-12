# Yandex Delivery: COD surcharge on `billing_info.delivery_cost` (offers/create)

Question (card Y7 / spec §5.4): under cash or card on delivery (`payment_method = card_on_receipt`) the offer
total is higher than the calculator price by a term that depends on the shipping amount. What is that term
exactly, and which number does the plugin need to add to the calculator quote (`quote = calc + k × D`)?

## Answer

1. **The term is 3.05 % of the COD amount collected, and `D` sits inside that amount.** The offer's
   `pricing_total` = `pricing` + `pricing_commission_on_delivery_payment_amount`. `pricing` does not move with
   `delivery_cost`. The commission equals **floor₂(0.0305 × (G + D))**, where `G` is the goods total
   (`items[].billing_details.unit_price` sum, RUB) and `D` is `billing_info.delivery_cost / 100` (RUB).
   All 24 measured COD points match this to the kopeck (residual 0.00 RUB).
2. **So the COD surcharge caused by the shipping amount is `k × D` with `k = 0.0305`**, floored to kopecks:
   `surcharge(D) = floor₂(0.0305 × (G + D)) − floor₂(0.0305 × G)`. Measured slope: 30.50 RUB per 1000 RUB of D
   (exactly 3.05 %). At `G = 3000 RUB` the goods term alone is 91.50 RUB, the same commission the calculator
   already includes. The spec's "k ≈ 0.03" is therefore 0.0305.
3. **Linear.** D from 0.01 RUB to 20 000 RUB: the increment is 0.0305 × D within one kopeck.
   **Rounding:** truncation (floor) to the kopeck, not round-half-up (50 kop → 91.51, not 91.52;
   123.45 RUB → 95.26, not 95.27). **Minimum:** none (0.01 RUB → 0.00). **Cap:** none up to 20 000 RUB
   (surcharge 610.00 RUB exactly).
4. **Dependencies.** The commission base is the goods total (`unit_price`) plus D, not the assessed value
   (assessed 0 keeps the commission at 91.50 for D = 0). The assessed value moves `pricing` only:
   `pricing` changes by 0.61 % of the assessed value (18.30 RUB for 3000 RUB; -12.20 for 1000 RUB; +42.70 for
   10 000 RUB). Pickup point Moscow and Samara give the same commission (Samara `pricing` is higher, 361.12 vs
   203.74 for the same parcel, which is a route price, not a COD term).
5. **5post: no COD surcharge exists to measure.** A Moscow 5post point refuses COD at offers/create with HTTP 400
   `Pickup point doesn't accept payment on delivery`. This matches spec §5.6 (no 5post point accepts COD).
6. **Prepaid (`already_paid`) has no term.** With `delivery_cost` = 1000 RUB sent under prepaid, `pricing_total` stays
   203.74 RUB and no commission field is returned.
7. **Y7 formula:** `COD quote = calc + floor₂(0.0305 × D)`, where `D` is the amount in RUB that the plugin sends in
   `billing_info.delivery_cost` (the shipping price without the surcharge). The calculator already contains the goods
   term, so the surcharge is added once. Against the offer, the only gap left is the calculator's own `ceil()` to
   whole RUB (up to 1 RUB, s174 README) plus the kopeck floor of this term (at most 0.01 RUB). Measured here:
   `offer_total(D) − offer_total(0) = floor₂(0.0305 × (G+D)) − floor₂(0.0305 × G)`, residual 0.00 in all 24 cases.
8. **Fixed point?** Not needed when `D` is the shipping price without the surcharge. If the design wants the surcharge
   itself to carry the commission, `D = S / (1 − 0.0305)` solves it in closed form (no iteration). That is a
   business choice, not something the API decides.
9. **Merchant markup is not separated by the API.** The commission applies to whatever number is sent in
   `delivery_cost`. If the markup is included in `D`, the markup carries the 3.05 % as well. The API cannot tell the
   carrier price from the markup (see UNVERIFIED on who pays it).

## Method

- Route: source = Moscow market_l4g drop-off `20cfe81c-bb45-4cfa-85e6-31fdade8ed23` (as in s174). Destination =
  Moscow market_l4g `05212278-441c-4033-9ed8-851ca27facca` (`Волгоградский проспект 10 стр2`, accepts
  `card_on_receipt`). Samara market_l4g `01a0ece738f473b7be140baf6bde5a51` for the second city.
- Parcel: one MEDIUM unit, 3 kg, 40 × 30 × 20 cm, `predefined_volume` 24 000 cm³ on the item and on the place
  (the same shape as s174 `medium`). Goods price 3000 RUB (`unit_price` 300 000 kop) unless stated.
- `delivery_cost` is sent in **kopecks** (RUB × 100), as in s174 (`shipping_kop_of()` = calc × 100).
- Each request has a unique `operator_request_id` (`r2-<case>`), so no response is served from an earlier one.
- Calls: `offers/create` only. No `confirm`, no `request/*`. Pacing 0.5 s. No 429.
- `measure_cod.py` builds the bodies and caches each request/response pair in `raw/` (no headers, no token).
  `fit.json` holds the model comparison; `results.json` holds one row per case.

## Results

Commission = `pricing_commission_on_delivery_payment_amount`. Surcharge is the commission above the same goods
with D = 0 (Moscow, goods 3000 RUB: 91.50). Every offers response returned 5 offers with identical totals.

| case | goods (RUB) | assessed (RUB) | D (RUB) | pricing | commission | model floor₂(0.0305·(G+D)) | pricing_total | surcharge vs D=0 |
|---|---|---|---|---|---|---|---|---|
| s1 Moscow, COD | 3000 | 3000 | 0 | 203.74 | 91.50 | 91.50 | 295.24 | 0.00 |
| s1 Moscow, COD | 3000 | 3000 | 0.01 | 203.74 | 91.50 | 91.50 | 295.24 | 0.00 |
| s1 Moscow, COD | 3000 | 3000 | 0.50 | 203.74 | 91.51 | 91.51 | 295.25 | 0.01 |
| s1 Moscow, COD | 3000 | 3000 | 0.99 | 203.74 | 91.53 | 91.53 | 295.27 | 0.03 |
| s1 Moscow, COD | 3000 | 3000 | 1 | 203.74 | 91.53 | 91.53 | 295.27 | 0.03 |
| s1 Moscow, COD | 3000 | 3000 | 100 | 203.74 | 94.55 | 94.55 | 298.29 | 3.05 |
| s1 Moscow, COD | 3000 | 3000 | 123.45 | 203.74 | 95.26 | 95.26 | 299.00 | 3.76 |
| s1 Moscow, COD | 3000 | 3000 | 300 | 203.74 | 100.65 | 100.65 | 304.39 | 9.15 |
| s1 Moscow, COD | 3000 | 3000 | 500 | 203.74 | 106.75 | 106.75 | 310.49 | 15.25 |
| s1 Moscow, COD | 3000 | 3000 | 777.77 | 203.74 | 115.22 | 115.22 | 318.96 | 23.72 |
| s1 Moscow, COD | 3000 | 3000 | 1000 | 203.74 | 122.00 | 122.00 | 325.74 | 30.50 |
| s1 Moscow, COD | 3000 | 3000 | 2000 | 203.74 | 152.50 | 152.50 | 356.24 | 61.00 |
| s1 Moscow, COD | 3000 | 3000 | 5000 | 203.74 | 244.00 | 244.00 | 447.74 | 152.50 |
| s1 Moscow, COD | 3000 | 3000 | 20000 | 203.74 | 701.50 | 701.50 | 905.24 | 610.00 |
| s1 repeat | 3000 | 3000 | 300 | 203.74 | 100.65 | 100.65 | 304.39 | 9.15 (same) |
| s1 repeat | 3000 | 3000 | 1000 | 203.74 | 122.00 | 122.00 | 325.74 | 30.50 (same) |
| s2 Moscow, COD | 1000 | 1000 | 1000 | 191.54 | 61.00 | 61.00 | 252.54 | n/a (no D=0 point at this goods) |
| s2 Moscow, COD | 5000 | 5000 | 1000 | 215.94 | 183.00 | 183.00 | 398.94 | n/a |
| s2 Moscow, COD | 10000 | 10000 | 1000 | 246.44 | 335.50 | 335.50 | 581.94 | n/a |
| s2 Moscow, COD | 3000 | 0 | 0 | 185.44 | 91.50 | 91.50 | 276.94 | base |
| s2 Moscow, COD | 3000 | 0 | 1000 | 185.44 | 122.00 | 122.00 | 307.44 | 30.50 |
| s3 Samara, COD | 3000 | 3000 | 0 | 361.12 | 91.50 | 91.50 | 452.62 | 0.00 |
| s3 Samara, COD | 3000 | 3000 | 1000 | 361.12 | 122.00 | 122.00 | 483.12 | 30.50 |
| s3 Samara, COD | 3000 | 3000 | 5000 | 361.12 | 244.00 | 244.00 | 605.12 | 152.50 |
| s4 Moscow 5post, COD | 3000 | 3000 | 0 | HTTP 400 `Pickup point doesn't accept payment on delivery` (no offer) | | | | n/a |
| s5 Moscow, prepaid | 3000 | 3000 | 0 | 203.74 | none | none | 203.74 | 0.00 |
| s5 Moscow, prepaid | 3000 | 3000 | 1000 | 203.74 | none | none | 203.74 | 0.00 (control) |

Not sent (skipped after the 5post refusal): 5post at D = 1000 and 5000 RUB.

Fit (`fit.json`, 24 COD cases): `observed − floor₂(0.0305 × (G + D))` = 0.00 in every case. Without the floor the
linear model differs by up to 0.005 RUB (e.g. 0.5 RUB: 91.51525 linear, 91.51 observed).

## Requests used

- `offers/create`: 27 calls (26 × HTTP 200, 1 × HTTP 400). Budget ≤ 60.
- `pricing-calculator`: 0 (budget ≤ 10). Not needed: surcharge is measured against offer(D=0) on the same route.
- `offers/confirm`, `request/*`: 0. HTTP 429: none.

## UNVERIFIED

- **The mechanism of 3.05 %.** `pricing_commission_on_delivery_payment` reads `2.5%` in every case, and
  2.5 % × 1.22 = 3.05 %, and 0.5 % × 1.22 = 0.61 % for the assessed fee. This suggests 2.5 % / 0.5 % plus 22 % VAT.
  Not proven: no VAT field was returned.
- **Who pays the commission** (merchant or buyer, and whether it is taken from the COD cash): not measured. The API
  only returns it as part of `pricing_total`.
- **Other ranges:** goods only 1000–10 000 RUB; D only up to 20 000 RUB; one parcel, one pickup point per city, one
  tariff. Whether k changes for other goods values, other parcels, courier, or for D above 20 000 RUB is untested.
- **5post with COD:** cannot be measured (refused). Any 5post COD surcharge is moot unless a COD-accepting 5post exists.
- **Whether `D` is charged to the buyer** or only accounted by the carrier: outside the API, not measured.
- The calculator's own COD term (about 91.5 RUB at 3000 RUB, from s174) is assumed to equal `floor₂(0.0305 × G)`.
  s174 numbers agree (91.5), but that is not re-measured here.

## Files

- `README.md` — this file.
- `measure_cod.py` — `run` (offers/create, cached), `summarize` (results.json), `fit` (fit.json).
- `raw/` — 27 request/response records (`{request, status, response}`), no headers, no token.
- `results.json` — one row per case (both 200 and the 400 case, two skipped cases marked missing).
- `fit.json` — model comparison per COD case.

## Related

- Spec: [`../../specs/2026-10-12-yandex-v2-plugin-design.md`](../../specs/2026-10-12-yandex-v2-plugin-design.md) §5.4 (`quote = calc + k × D`; the k ≈ 0.03 there should be 0.0305), §5.6 (5post refuses COD).
- s174 price measurement: [`../2026-10-12-yandex-price-measurement/README.md`](../2026-10-12-yandex-price-measurement/README.md) (the 3 % term first seen, COD commission ≈ 91 RUB).
- s174 geo measurement: [`../2026-10-12-yandex-geo-measurement/README.md`](../2026-10-12-yandex-geo-measurement/README.md) (geo ids).
