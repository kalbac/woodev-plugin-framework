# Yandex Delivery: checkout price vs cabinet price (v1 calculator vs offers/create)

Question (operator): the customer sees price X at checkout, the order exported to Yandex shows price Y in the
cabinet. Do the v1 plugin's two calls send different parameters, and which ones move the price?

## Answer

Yes. The two calls send different pricing inputs, and three of them explain the whole gap:

1. the calculator charges a **volumetric** size step (+28 RUB per whole kg once L x W x H / 5000 exceeds the
   actual weight); the offer does not;
2. the offer **always** carries the assessed value (about 18 RUB); the calculator adds it only when the
   insurance option is on;
3. for COD the offer adds about 3 % of `billing_info.delivery_cost` (the shipping charge); the calculator never
   receives it.

Once those three terms are accounted for (the box set to 1 cm and insurance on in the calculator; for COD also
the offer's `delivery_cost` term removed), the two match within 1 RUB in every measured case. The residual is
rounding: the calculator rounds up, the admin list truncates. The cabinet price is lower than the
checkout price in 158 of 170 cases (range -51.86 % to +10.49 %); higher in 11; equal in 1.

## Method

- Bodies are built the way v1 builds them, from the v1 source (see the table below). Helpers are reused where
  possible: the v1 packer (`woodev/box-packer`, the same PHP classes) runs under PHP CLI for `common_box`.
- Source: the market_l4g drop-off point nearest to central Moscow (`Москва Трубная площадь 2`, id
  `20cfe81c-bb45-4cfa-85e6-31fdade8ed23`). The account has no warehouse listing endpoint in the allowed set,
  so the warehouse path is not measured.
- Destinations per geo (`raw/ctx.json`): the first point v1 would pick (pickup_point list filtered by payment
  method), the first market_l4g point and the first 5post point that accept the payment method, and a courier
  address (from `COURIER_ADDRESS` in the script; see coverage for the geos without one).
- Parcel profiles: small (0.5 kg, 20x15x10 cm), medium (3 kg, 40x30x20), large (15 kg, 60x40x40), medium_x3
  (3 x medium), two_items (medium + small, different dims, 2000 + 1000 RUB). Cart subtotal 3000 RUB in all
  profiles (COD `client_price`, insurance value).
- Payment: prepaid (`already_paid`) and COD (`card_on_receipt`, `client_price` 3000 RUB).
- Insurance: off (no `total_assessed_price`, the v1 option `none`) and on (`total_assessed_price` 3000 RUB).
- Stage A (Moscow 213, Samara 51, Kaliningrad 22): all profiles, all combinations. Stage B (Saint Petersburg 2,
  Sochi 239, Timashevsk 21141, Volzhsk 20721, Drozhzhino MO 121363): medium and two_items, prepaid and COD,
  insurance on and off.
- Isolation: one parameter at a time on the calculator (towards the offer) and on the offer (towards the
  calculator), on five cases; then a model check on every measured case (see Conclusions).
- Only `pickup-points/list`, `pricing-calculator` and `offers/create` were called. No `offers/confirm`, no
  `request/create`. Throttle 300 ms, backoff on 429, roughly 860 requests in total against the budget of 1500.
- Recipient data is synthetic (`Measure Test`, `+79000000000`, `measure@example.com`). Not tested: whether
  recipient data changes the price.
- Units: all weights in grams (confirmed: the offer endpoint rejects 3000 kg with "Combined weight must not
  exceed the limit (1500 kg)" when weights are multiplied by 1000), dimensions in cm, money in kopecks in the
  request body, `total_weight` must be an integer.

## Step 1 - v1 parameter side-by-side

Calculator: `includes/class-shipping-method-other-day.php` `get_delivery_cost()` (lines 95-256), request
`api/class-request.php` `pricing_calculator()` (50-59), API wrapper `api/class-api.php` (117-133).
Offer: `includes/class-order.php` `prepare_order_data()` (169-301) and `create_offers()` (331-353); admin list
`includes/class-ajax.php` `get_order_offers()`; request `api/class-request.php` `create_offers()` (90-117).
Rounding and merchant adjustments: `includes/abstract-shipping-method.php` `calculate_shipping()` (202-250),
`get_rate_cost()` (254-307).

| Parameter | v1 calculator (`pricing-calculator`) | v1 offer (`offers/create`) | Price effect (measured) |
|---|---|---|---|
| Source station | `source.platform_station_id` (flat) | `source.platform_station.platform_id` (nested) | none observable; schema-rejected in each other's shape (see Coverage). Same id on both sides; UNVERIFIED that offers read it |
| Destination, pickup | `destination.platform_station_id` | `destination.type = platform_station`, `platform_station.platform_id` | same point id on both sides |
| Destination, courier | `destination.address` (formatted string) | `type = custom_location`, `custom_location.details.full_address`, `room` | same string on both sides |
| Tariff / last mile | `tariff` = `time_interval` or `self_pickup` | `last_mile_policy` = same values | same mapping |
| Weight | `total_weight` (int, grams) and `places[].physical_dims.weight_gross` (grams) | `items[].physical_dims.weight_gross` (grams per unit) and `places[]...weight_gross` (x count) | units identical; top-level `total_weight` has no effect when places carry weight |
| Dimensions | `places[].physical_dims` `dx` = length, `dy` = height, `dz` = width, per unit (required) | `physical_dims.predefined_volume` (cm3) only, no dx/dy/dz | **calculator adds a volumetric step; the offer ignores volume** (main driver) |
| Places granularity | one place per unit (sku_boxes) or one packed box (common_box, packer) | one place per SKU line (count, summed weight and volume) or one common place | no effect on either side in the tested cases |
| Barcode | n/a | `place_barcode` = SKU (sku_boxes) or order number (common_box, required) | none (validation only) |
| Insurance | `total_assessed_price` (kopecks) only if `include_insurance` is on | `items[].billing_details.assessed_unit_price` always (unit price x 100) | **offer always carries about 18 RUB; calculator only with the option** |
| COD amount | `client_price` = goods total x 100 (COD only) | none; COD expressed through `billing_info` | the COD commission (about 91 RUB) depends on `client_price` in the calculator (removing it: -91) and is present in the offer too |
| Payment method | `payment_method` (`already_paid` / `card_on_receipt`) | `billing_info.payment_method` (same values) | same; COD courier is rejected by the calculator (see Coverage) |
| COD delivery cost | none | `billing_info.delivery_cost` = shipping total x 100 (COD only) | **offer adds about 3 % of it (8 to 41 RUB); calculator ignores it** |
| Interval | none | `destination.interval_utc` stripped by `create_offers()` | not sent, no effect |
| VAT | none | `items[].billing_details.nds` = -1 (no VAT) | UNVERIFIED: neither response shows a VAT split |
| Rounding | `ceil()` of `pricing_total` (get_delivery_cost) | admin list `intval()` of the offer `pricing_total` | up to 1 RUB |
| Caching | `pricing-calculator` is not `bypass_cache()`d: v1 may serve a cached quote | `create_offers` bypasses the cache | the checkout number may be stale |
| Merchant adjustments | fee, free-shipping, min/max, round_cost applied to the checkout rate only | not applied to the export | NOT measured (raw API numbers only, no adjustments set) |

## Step 2 - results per case

Columns: v1 calculator price (ceil, as the checkout shows it), cheapest offer (truncated, as the admin list
shows it), the offer v1 shows first (the admin list has no automatic choice; the first in list order), delta =
offer - calculator in RUB and %. In every case the first offer is the cheapest offer (170 of
170); all offers of one request share one price and differ only in delivery intervals.

Stage A (102 cases):

| case | v1 calculator (ceil) | cheapest offer (intval) | offer v1 list shows first | delta RUB (offer - calc) | delta % |
|---|---|---|---|---|---|
| g213-courier-large-sku_boxes-prepaid-off | 824 | 701 | 701 | -123 | -14.93 |
| g213-courier-large-sku_boxes-prepaid-on | 842 | 701 | 701 | -141 | -16.75 |
| g213-courier-medium-sku_boxes-prepaid-off | 403 | 364 | 364 | -39 | -9.68 |
| g213-courier-medium-sku_boxes-prepaid-on | 421 | 364 | 364 | -57 | -13.54 |
| g213-courier-medium_x3-sku_boxes-prepaid-off | 684 | 533 | 533 | -151 | -22.08 |
| g213-courier-medium_x3-sku_boxes-prepaid-on | 702 | 533 | 533 | -169 | -24.07 |
| g213-courier-small-sku_boxes-prepaid-off | 319 | 336 | 336 | +17 | +5.33 |
| g213-courier-small-sku_boxes-prepaid-on | 337 | 336 | 336 | -1 | -0.30 |
| g213-courier-two_items-common_box-prepaid-off | 487 | 392 | 392 | -95 | -19.51 |
| g213-courier-two_items-common_box-prepaid-on | 506 | 392 | 392 | -114 | -22.53 |
| g213-courier-two_items-sku_boxes-prepaid-off | 487 | 392 | 392 | -95 | -19.51 |
| g213-courier-two_items-sku_boxes-prepaid-on | 506 | 392 | 392 | -114 | -22.53 |
| g213-pickup_5post-medium-sku_boxes-prepaid-off | 330 | 262 | 262 | -68 | -20.61 |
| g213-pickup_5post-medium-sku_boxes-prepaid-on | 348 | 262 | 262 | -86 | -24.71 |
| g213-pickup_5post-medium_x3-sku_boxes-prepaid-off | 610 | 347 | 347 | -263 | -43.11 |
| g213-pickup_5post-medium_x3-sku_boxes-prepaid-on | 629 | 347 | 347 | -282 | -44.83 |
| g213-pickup_5post-small-sku_boxes-prepaid-off | 220 | 237 | 237 | +17 | +7.73 |
| g213-pickup_5post-small-sku_boxes-prepaid-on | 238 | 237 | 237 | -1 | -0.42 |
| g213-pickup_5post-two_items-common_box-prepaid-off | 452 | 262 | 262 | -190 | -42.04 |
| g213-pickup_5post-two_items-common_box-prepaid-on | 470 | 262 | 262 | -208 | -44.26 |
| g213-pickup_5post-two_items-sku_boxes-prepaid-off | 452 | 262 | 262 | -190 | -42.04 |
| g213-pickup_5post-two_items-sku_boxes-prepaid-on | 470 | 262 | 262 | -208 | -44.26 |
| g213-pickup_market-large-sku_boxes-cod-off | 692 | 609 | 609 | -83 | -11.99 |
| g213-pickup_market-large-sku_boxes-cod-on | 711 | 609 | 609 | -102 | -14.35 |
| g213-pickup_market-large-sku_boxes-prepaid-off | 601 | 496 | 496 | -105 | -17.47 |
| g213-pickup_market-large-sku_boxes-prepaid-on | 619 | 496 | 496 | -123 | -19.87 |
| g213-pickup_market-medium-sku_boxes-cod-off | 326 | 305 | 305 | -21 | -6.44 |
| g213-pickup_market-medium-sku_boxes-cod-on | 345 | 305 | 305 | -40 | -11.59 |
| g213-pickup_market-medium-sku_boxes-prepaid-off | 235 | 203 | 203 | -32 | -13.62 |
| g213-pickup_market-medium-sku_boxes-prepaid-on | 253 | 203 | 203 | -50 | -19.76 |
| g213-pickup_market-medium_x3-sku_boxes-cod-off | 570 | 459 | 459 | -111 | -19.47 |
| g213-pickup_market-medium_x3-sku_boxes-cod-on | 589 | 459 | 459 | -130 | -22.07 |
| g213-pickup_market-medium_x3-sku_boxes-prepaid-off | 479 | 350 | 350 | -129 | -26.93 |
| g213-pickup_market-medium_x3-sku_boxes-prepaid-on | 497 | 350 | 350 | -147 | -29.58 |
| g213-pickup_market-small-sku_boxes-cod-off | 253 | 278 | 278 | +25 | +9.88 |
| g213-pickup_market-small-sku_boxes-cod-on | 271 | 279 | 279 | +8 | +2.95 |
| g213-pickup_market-small-sku_boxes-prepaid-off | 162 | 179 | 179 | +17 | +10.49 |
| g213-pickup_market-small-sku_boxes-prepaid-on | 180 | 179 | 179 | -1 | -0.56 |
| g213-pickup_market-two_items-common_box-cod-off | 399 | 331 | 331 | -68 | -17.04 |
| g213-pickup_market-two_items-common_box-cod-on | 418 | 332 | 332 | -86 | -20.57 |
| g213-pickup_market-two_items-common_box-prepaid-off | 308 | 228 | 228 | -80 | -25.97 |
| g213-pickup_market-two_items-common_box-prepaid-on | 326 | 228 | 228 | -98 | -30.06 |
| g213-pickup_market-two_items-sku_boxes-cod-off | 399 | 331 | 331 | -68 | -17.04 |
| g213-pickup_market-two_items-sku_boxes-cod-on | 418 | 332 | 332 | -86 | -20.57 |
| g213-pickup_market-two_items-sku_boxes-prepaid-off | 308 | 228 | 228 | -80 | -25.97 |
| g213-pickup_market-two_items-sku_boxes-prepaid-on | 326 | 228 | 228 | -98 | -30.06 |
| g22-pickup_5post-medium-sku_boxes-prepaid-off | 1089 | 867 | 867 | -222 | -20.39 |
| g22-pickup_5post-medium-sku_boxes-prepaid-on | 1107 | 867 | 867 | -240 | -21.68 |
| g22-pickup_5post-medium_x3-sku_boxes-prepaid-off | 2284 | 1584 | 1584 | -700 | -30.65 |
| g22-pickup_5post-medium_x3-sku_boxes-prepaid-on | 2303 | 1584 | 1584 | -719 | -31.22 |
| g22-pickup_5post-small-sku_boxes-prepaid-off | 850 | 867 | 867 | +17 | +2.00 |
| g22-pickup_5post-small-sku_boxes-prepaid-on | 868 | 867 | 867 | -1 | -0.12 |
| g22-pickup_5post-two_items-common_box-prepaid-off | 1447 | 986 | 986 | -461 | -31.86 |
| g22-pickup_5post-two_items-common_box-prepaid-on | 1466 | 986 | 986 | -480 | -32.74 |
| g22-pickup_5post-two_items-sku_boxes-prepaid-off | 1447 | 986 | 986 | -461 | -31.86 |
| g22-pickup_5post-two_items-sku_boxes-prepaid-on | 1466 | 986 | 986 | -480 | -32.74 |
| g51-courier-large-sku_boxes-prepaid-off | 1513 | 1250 | 1250 | -263 | -17.38 |
| g51-courier-large-sku_boxes-prepaid-on | 1532 | 1250 | 1250 | -282 | -18.41 |
| g51-courier-medium-sku_boxes-prepaid-off | 671 | 577 | 577 | -94 | -14.01 |
| g51-courier-medium-sku_boxes-prepaid-on | 690 | 577 | 577 | -113 | -16.38 |
| g51-courier-medium_x3-sku_boxes-prepaid-off | 1233 | 913 | 913 | -320 | -25.95 |
| g51-courier-medium_x3-sku_boxes-prepaid-on | 1251 | 913 | 913 | -338 | -27.02 |
| g51-courier-small-sku_boxes-prepaid-off | 451 | 468 | 468 | +17 | +3.77 |
| g51-courier-small-sku_boxes-prepaid-on | 469 | 468 | 468 | -1 | -0.21 |
| g51-courier-two_items-common_box-prepaid-off | 840 | 633 | 633 | -207 | -24.64 |
| g51-courier-two_items-common_box-prepaid-on | 858 | 633 | 633 | -225 | -26.22 |
| g51-courier-two_items-sku_boxes-prepaid-off | 840 | 633 | 633 | -207 | -24.64 |
| g51-courier-two_items-sku_boxes-prepaid-on | 858 | 633 | 633 | -225 | -26.22 |
| g51-pickup_5post-medium-sku_boxes-prepaid-off | 582 | 488 | 488 | -94 | -16.15 |
| g51-pickup_5post-medium-sku_boxes-prepaid-on | 601 | 488 | 488 | -113 | -18.80 |
| g51-pickup_5post-medium_x3-sku_boxes-prepaid-off | 1144 | 824 | 824 | -320 | -27.97 |
| g51-pickup_5post-medium_x3-sku_boxes-prepaid-on | 1162 | 824 | 824 | -338 | -29.09 |
| g51-pickup_5post-small-sku_boxes-prepaid-off | 470 | 488 | 488 | +18 | +3.83 |
| g51-pickup_5post-small-sku_boxes-prepaid-on | 488 | 488 | 488 | +0 | +0.00 |
| g51-pickup_5post-two_items-common_box-prepaid-off | 751 | 544 | 544 | -207 | -27.56 |
| g51-pickup_5post-two_items-common_box-prepaid-on | 769 | 544 | 544 | -225 | -29.26 |
| g51-pickup_5post-two_items-sku_boxes-prepaid-off | 751 | 544 | 544 | -207 | -27.56 |
| g51-pickup_5post-two_items-sku_boxes-prepaid-on | 769 | 544 | 544 | -225 | -29.26 |
| g51-pickup_market-large-sku_boxes-cod-off | 1327 | 1122 | 1122 | -205 | -15.45 |
| g51-pickup_market-large-sku_boxes-cod-on | 1345 | 1123 | 1123 | -222 | -16.51 |
| g51-pickup_market-large-sku_boxes-prepaid-off | 1235 | 990 | 990 | -245 | -19.84 |
| g51-pickup_market-large-sku_boxes-prepaid-on | 1253 | 990 | 990 | -263 | -20.99 |
| g51-pickup_market-medium-sku_boxes-cod-off | 540 | 469 | 469 | -71 | -13.15 |
| g51-pickup_market-medium-sku_boxes-cod-on | 558 | 469 | 469 | -89 | -15.95 |
| g51-pickup_market-medium-sku_boxes-prepaid-off | 448 | 361 | 361 | -87 | -19.42 |
| g51-pickup_market-medium-sku_boxes-prepaid-on | 467 | 361 | 361 | -106 | -22.70 |
| g51-pickup_market-medium_x3-sku_boxes-cod-off | 1064 | 799 | 799 | -265 | -24.91 |
| g51-pickup_market-medium_x3-sku_boxes-cod-on | 1083 | 800 | 800 | -283 | -26.13 |
| g51-pickup_market-medium_x3-sku_boxes-prepaid-off | 973 | 675 | 675 | -298 | -30.63 |
| g51-pickup_market-medium_x3-sku_boxes-prepaid-on | 991 | 675 | 675 | -316 | -31.89 |
| g51-pickup_market-small-sku_boxes-cod-off | 336 | 364 | 364 | +28 | +8.33 |
| g51-pickup_market-small-sku_boxes-cod-on | 354 | 364 | 364 | +10 | +2.82 |
| g51-pickup_market-small-sku_boxes-prepaid-off | 244 | 262 | 262 | +18 | +7.38 |
| g51-pickup_market-small-sku_boxes-prepaid-on | 263 | 262 | 262 | -1 | -0.38 |
| g51-pickup_market-two_items-common_box-cod-off | 697 | 526 | 526 | -171 | -24.53 |
| g51-pickup_market-two_items-common_box-cod-on | 715 | 526 | 526 | -189 | -26.43 |
| g51-pickup_market-two_items-common_box-prepaid-off | 606 | 413 | 413 | -193 | -31.85 |
| g51-pickup_market-two_items-common_box-prepaid-on | 624 | 413 | 413 | -211 | -33.81 |
| g51-pickup_market-two_items-sku_boxes-cod-off | 697 | 526 | 526 | -171 | -24.53 |
| g51-pickup_market-two_items-sku_boxes-cod-on | 715 | 526 | 526 | -189 | -26.43 |
| g51-pickup_market-two_items-sku_boxes-prepaid-off | 606 | 413 | 413 | -193 | -31.85 |
| g51-pickup_market-two_items-sku_boxes-prepaid-on | 624 | 413 | 413 | -211 | -33.81 |

Stage B (68 cases):

| case | v1 calculator (ceil) | cheapest offer (intval) | offer v1 list shows first | delta RUB (offer - calc) | delta % |
|---|---|---|---|---|---|
| g121363-pickup_market-medium-sku_boxes-cod-off | 326 | 305 | 305 | -21 | -6.44 |
| g121363-pickup_market-medium-sku_boxes-cod-on | 345 | 305 | 305 | -40 | -11.59 |
| g121363-pickup_market-medium-sku_boxes-prepaid-off | 235 | 203 | 203 | -32 | -13.62 |
| g121363-pickup_market-medium-sku_boxes-prepaid-on | 253 | 203 | 203 | -50 | -19.76 |
| g121363-pickup_market-two_items-sku_boxes-cod-off | 399 | 331 | 331 | -68 | -17.04 |
| g121363-pickup_market-two_items-sku_boxes-cod-on | 418 | 332 | 332 | -86 | -20.57 |
| g121363-pickup_market-two_items-sku_boxes-prepaid-off | 308 | 228 | 228 | -80 | -25.97 |
| g121363-pickup_market-two_items-sku_boxes-prepaid-on | 326 | 228 | 228 | -98 | -30.06 |
| g2-courier-medium-sku_boxes-prepaid-off | 553 | 497 | 497 | -56 | -10.13 |
| g2-courier-medium-sku_boxes-prepaid-on | 571 | 497 | 497 | -74 | -12.96 |
| g2-courier-two_items-sku_boxes-prepaid-off | 663 | 534 | 534 | -129 | -19.46 |
| g2-courier-two_items-sku_boxes-prepaid-on | 681 | 534 | 534 | -147 | -21.59 |
| g2-pickup_5post-medium-sku_boxes-prepaid-off | 458 | 323 | 323 | -135 | -29.48 |
| g2-pickup_5post-medium-sku_boxes-prepaid-on | 476 | 323 | 323 | -153 | -32.14 |
| g2-pickup_5post-two_items-sku_boxes-prepaid-off | 653 | 323 | 323 | -330 | -50.54 |
| g2-pickup_5post-two_items-sku_boxes-prepaid-on | 671 | 323 | 323 | -348 | -51.86 |
| g2-pickup_market-medium-sku_boxes-cod-off | 420 | 387 | 387 | -33 | -7.86 |
| g2-pickup_market-medium-sku_boxes-cod-on | 438 | 387 | 387 | -51 | -11.64 |
| g2-pickup_market-medium-sku_boxes-prepaid-off | 329 | 283 | 283 | -46 | -13.98 |
| g2-pickup_market-medium-sku_boxes-prepaid-on | 347 | 283 | 283 | -64 | -18.44 |
| g2-pickup_market-two_items-sku_boxes-cod-off | 515 | 421 | 421 | -94 | -18.25 |
| g2-pickup_market-two_items-sku_boxes-cod-on | 534 | 422 | 422 | -112 | -20.97 |
| g2-pickup_market-two_items-sku_boxes-prepaid-off | 424 | 314 | 314 | -110 | -25.94 |
| g2-pickup_market-two_items-sku_boxes-prepaid-on | 442 | 314 | 314 | -128 | -28.96 |
| g20721-pickup_5post-medium-sku_boxes-prepaid-off | 610 | 516 | 516 | -94 | -15.41 |
| g20721-pickup_5post-medium-sku_boxes-prepaid-on | 629 | 516 | 516 | -113 | -17.97 |
| g20721-pickup_5post-two_items-sku_boxes-prepaid-off | 779 | 572 | 572 | -207 | -26.57 |
| g20721-pickup_5post-two_items-sku_boxes-prepaid-on | 797 | 572 | 572 | -225 | -28.23 |
| g20721-pickup_market-medium-sku_boxes-cod-off | 515 | 451 | 451 | -64 | -12.43 |
| g20721-pickup_market-medium-sku_boxes-cod-on | 534 | 451 | 451 | -83 | -15.54 |
| g20721-pickup_market-medium-sku_boxes-prepaid-off | 424 | 344 | 344 | -80 | -18.87 |
| g20721-pickup_market-medium-sku_boxes-prepaid-on | 442 | 344 | 344 | -98 | -22.17 |
| g20721-pickup_market-two_items-sku_boxes-cod-off | 662 | 504 | 504 | -158 | -23.87 |
| g20721-pickup_market-two_items-sku_boxes-cod-on | 680 | 505 | 505 | -175 | -25.74 |
| g20721-pickup_market-two_items-sku_boxes-prepaid-off | 570 | 392 | 392 | -178 | -31.23 |
| g20721-pickup_market-two_items-sku_boxes-prepaid-on | 589 | 392 | 392 | -197 | -33.45 |
| g21141-courier-medium-sku_boxes-prepaid-off | 659 | 572 | 572 | -87 | -13.20 |
| g21141-courier-medium-sku_boxes-prepaid-on | 678 | 572 | 572 | -106 | -15.63 |
| g21141-courier-two_items-sku_boxes-prepaid-off | 817 | 624 | 624 | -193 | -23.62 |
| g21141-courier-two_items-sku_boxes-prepaid-on | 835 | 624 | 624 | -211 | -25.27 |
| g21141-pickup_5post-medium-sku_boxes-prepaid-off | 590 | 495 | 495 | -95 | -16.10 |
| g21141-pickup_5post-medium-sku_boxes-prepaid-on | 608 | 495 | 495 | -113 | -18.59 |
| g21141-pickup_5post-two_items-sku_boxes-prepaid-off | 758 | 551 | 551 | -207 | -27.31 |
| g21141-pickup_5post-two_items-sku_boxes-prepaid-on | 776 | 551 | 551 | -225 | -28.99 |
| g21141-pickup_market-medium-sku_boxes-cod-off | 528 | 463 | 463 | -65 | -12.31 |
| g21141-pickup_market-medium-sku_boxes-cod-on | 546 | 464 | 464 | -82 | -15.02 |
| g21141-pickup_market-medium-sku_boxes-prepaid-off | 436 | 356 | 356 | -80 | -18.35 |
| g21141-pickup_market-medium-sku_boxes-prepaid-on | 454 | 356 | 356 | -98 | -21.59 |
| g21141-pickup_market-two_items-sku_boxes-cod-off | 674 | 517 | 517 | -157 | -23.29 |
| g21141-pickup_market-two_items-sku_boxes-cod-on | 692 | 517 | 517 | -175 | -25.29 |
| g21141-pickup_market-two_items-sku_boxes-prepaid-off | 582 | 405 | 405 | -177 | -30.41 |
| g21141-pickup_market-two_items-sku_boxes-prepaid-on | 601 | 405 | 405 | -196 | -32.61 |
| g239-courier-medium-sku_boxes-prepaid-off | 659 | 572 | 572 | -87 | -13.20 |
| g239-courier-medium-sku_boxes-prepaid-on | 678 | 572 | 572 | -106 | -15.63 |
| g239-courier-two_items-sku_boxes-prepaid-off | 817 | 624 | 624 | -193 | -23.62 |
| g239-courier-two_items-sku_boxes-prepaid-on | 835 | 624 | 624 | -211 | -25.27 |
| g239-pickup_5post-medium-sku_boxes-prepaid-off | 590 | 495 | 495 | -95 | -16.10 |
| g239-pickup_5post-medium-sku_boxes-prepaid-on | 608 | 495 | 495 | -113 | -18.59 |
| g239-pickup_5post-two_items-sku_boxes-prepaid-off | 758 | 551 | 551 | -207 | -27.31 |
| g239-pickup_5post-two_items-sku_boxes-prepaid-on | 776 | 551 | 551 | -225 | -28.99 |
| g239-pickup_market-medium-sku_boxes-cod-off | 528 | 463 | 463 | -65 | -12.31 |
| g239-pickup_market-medium-sku_boxes-cod-on | 546 | 464 | 464 | -82 | -15.02 |
| g239-pickup_market-medium-sku_boxes-prepaid-off | 436 | 356 | 356 | -80 | -18.35 |
| g239-pickup_market-medium-sku_boxes-prepaid-on | 454 | 356 | 356 | -98 | -21.59 |
| g239-pickup_market-two_items-sku_boxes-cod-off | 674 | 517 | 517 | -157 | -23.29 |
| g239-pickup_market-two_items-sku_boxes-cod-on | 692 | 517 | 517 | -175 | -25.29 |
| g239-pickup_market-two_items-sku_boxes-prepaid-off | 582 | 405 | 405 | -177 | -30.41 |
| g239-pickup_market-two_items-sku_boxes-prepaid-on | 601 | 405 | 405 | -196 | -32.61 |

## Isolation

Five cases, one change at a time. Columns: the calculator price, the change, and the gap to the cheapest offer
after the change (offer - calculator).

Probe on the medium courier case (prepaid, no insurance):

| probe (one change) | calc / offer result | move | gap to cheapest offer after change |
|---|---|---|---|
| baseline | 403 (402.6 RUB) | - | -39 (offer 364) |
| calc dims_one_cm | 347 (346.48 RUB) | -56 | +17 |
| calc assessed_flip | 421 (420.9 RUB) | +18 | -57 |
| calc package_flip | 403 (402.6 RUB) | +0 | -39 |
| calc no_total_weight | 403 (402.6 RUB) | +0 | -39 |
| calc dims_one_cm+assessed_flip | 365 (364.78 RUB) | -38 | -1 |
| offer volume_one_cm3 | offer 364 | +0 | -39 |
| offer assessed_zero | offer 346 | -18 | -57 |
| offer places_per_unit | offer 364 | +0 | -39 |
| offer package_flip | offer 364 | +0 | -39 |

Decomposition on five cases. `dims -> 1 cm` sets every place to 1 x 1 x 1 cm (removes the volumetric step).
`model` additionally turns insurance on (the offer carries it). `residual` is what is left:

| case | v1 calc | calc, box = 1 cm | calc, box = 1 cm + insurance (model) | cheapest offer | offer, delivery_cost = 0 | residual (offer - model) | residual with delivery_cost = 0 |
|---|---|---|---|---|---|---|---|
| g213-courier-large-sku_boxes-prepaid-off | 824 | 684 | 702 | 701 | n/a | -1 | n/a |
| g213-courier-medium-sku_boxes-prepaid-off | 403 | 347 | 365 | 364 | n/a | -1 | n/a |
| g213-courier-small-sku_boxes-prepaid-off | 319 | 319 | 337 | 336 | n/a | -1 | n/a |
| g213-pickup_market-medium_x3-sku_boxes-cod-off | 570 | 424 | 442 | 459 | 441 | +17 | -1 |
| g22-pickup_5post-medium-sku_boxes-prepaid-off | 1089 | 850 | 868 | 867 | n/a | -1 | n/a |

Other single-parameter results (all in `tables.md`, section Isolation): assessed off on the offer lowers it by
18-19 RUB; `no_client_price` on COD lowers the calculator by 91 RUB to the prepaid level; `cod_delivery_cost`
does not change the calculator; `package_flip`, `places_per_unit` and `no_total_weight` change nothing.

Volumetric step (`probe_dims`, medium courier, prepaid, same weight):

| box (cm) | volume (cm3) | calculator RUB |
|---|---|---|
| 25 x 20 x 30 | 15000 | 347 |
| 25 x 20 x 31 | 15500 | 375 |
| 40 x 30 x 20 (v1 medium) | 24000 | 403 |
| 20 x 20 x 60 | 24000 | 403 |
| 30 x 30 x 27 | 24300 | 403 |
| 40 x 1 x 1 | 40 | 347 |
| 1 x 30 x 20 | 600 | 347 |

The threshold sits exactly where volume / 5000 crosses 3 kg. Each whole kg above the actual weight adds 28 RUB
(medium: 4.8 kg rounds to 5 kg, +56; large: 19.2 kg rounds to 20 kg, +140). Two boxes of equal volume and
different shape cost the same. The 10 x 10 x 240 box has no delivery option at all.

## Conclusions

1. The calculator and the offer are priced differently for the same route, tariff and goods. Over 170
   cases the offer is below the calculator in 158, above in 11, equal in 1;
   the spread is -51.86 % to +10.49 %.
2. The calculator prices a volumetric size: a step of +28 RUB per whole kg once volume / 5000 exceeds the
   actual weight (inferred from the probes, see UNVERIFIED). The offer does not: a 1 cm3 `predefined_volume`
   gives the same offer price. This is the main gap for medium, large and 5post parcels (up to 239 RUB on
   5post medium).
3. Insurance: the offer always carries the assessed unit price, which adds about 18 RUB. The calculator adds
   the same amount only with `include_insurance` on. For the small parcel (no volumetric step) this one
   difference closes the gap alone (337 vs 336, rounding only).
4. COD: both sides include the COD commission (about 91 RUB at a 3000 RUB `client_price`; removing
   `client_price` from the calculator gives the prepaid price). The offer adds a further
   8 to 41 RUB, about 3 % of `billing_info.delivery_cost`, which the calculator never
   sees (`cod_delivery_cost` does not move the calculator).
5. Model check on all 170 cases: calculator with the box set to 1 cm and insurance on, compared with the
   cheapest offer. Prepaid (126 cases): residual -1 to 0 RUB. COD
   (44 cases), with the offer computed without `delivery_cost`: residual -1 to -1 RUB.
   Remaining differences are rounding (calculator ceil, admin list truncation).
6. Weight: `total_weight` at the top level does not change the price when places carry weight; grams on both
   endpoints. Place count (per unit, per SKU, one packed box) changes nothing on either side.
7. Which offer v1 shows does not change the price (all offers of one request have one price; first = cheapest in
   every case). The auto path (`request/create`) was not measured by rule.
8. What v1 does with these facts is the operator's decision. The three terms above are the only price
   differences found: volumetric size and COD delivery cost (checkout is higher / the cabinet lower), and the
   insurance value (checkout lower unless the option is on).

## Coverage and API refusals

Planned grid: 170 priced cases. Planned cells that produced no comparison:
- no point of this kind for this payment: 84
- API error (see raw/): 42
- courier address not geocodable: 40

Refusals (kept in `raw/`):

- COD courier: `pricing-calculator` returns 400 "Payment method 'card' is not allowed for courier delivery" for
  `card_on_receipt` on every courier COD request (36 requests). COD courier cannot be quoted here.
- 5post large (60 x 40 x 40 cm, 15 kg): "Dimensions should not exceed limit" (6 requests).
- Courier address not geocodable ("can't restore coordinates from address") in Kaliningrad, Volzhsk and
  Drozhzhino MO: 4 address variants tried in each geo (12 in total) (`raw/probe/courier-addresses.json`). Either no courier service
  there or the addresses are wrong; not resolved.
- Kaliningrad: no point accepts COD (any operator) and no market_l4g point exists; only 5post prepaid.
  Moscow 5post does not accept COD.
- Schema probes (`raw/iso`): the calculator rejects `dx/dy/dz`-less places and the offer shape for
  `source`/`destination`, and the offer rejects the calculator shape. The calculator rejects float
  `total_weight` (0.5 kg). These were probes, not price data.

## UNVERIFIED

- The volumetric divisor 5000 and the 28 RUB/kg step: inferred from the probes above; the Yandex documentation
  linked in the v1 code was not read.
- The mechanism of the offer's delivery-cost charge (about 3 %) and of the COD commission base (the response
  reports 2.5 % and 91.5 RUB; the base was not derived).
- Whether the offer price depends on the source station: the source was never changed, and the offer endpoint
  rejects the calculator shape, so only the identical id is known.
- VAT: neither response shows a VAT split; inclusion on either side is not determined.
- Merchant-side adjustments (fee, free shipping, min/max, round_cost) and the v1 quote cache: not in these raw
  numbers. The customer-visible checkout price may differ further.
- `request/create` (automatic path) not measured.
- Explicit `total_assessed_price: 0` was not tested (only absent vs 3000 RUB).
- Warehouse as source not tested; drop-off point only.
- Recipient and phone do not appear to be measured; they were synthetic.
- `get_item_total()` is assumed to be the WooCommerce per-unit cost (`assessed_unit_price` for a quantity-3 line);
  not read in this session.
- Courier unavailable or misaddressed in three geos: not resolved.
- Prices are a snapshot of 12.10.2026.

## Files

- `measure_price.py`: `discover`, `stage A|B`, `isolate`, `probe_dims`, `confirm`, `summarize`. Token read at
  runtime from `.wp-env.override.json`; nothing printed or written.
- `raw/`: `ctx.json` (the points used, trimmed), `A/`, `B/` (calculator and offer request + status + response),
  `iso/` (isolation and schema probes), `probe/` (address and volume probes). No headers, no tokens.
- `results.json`, `tables.md` (all per-case and isolation rows), `confirm.json` (model check per case).

## Follow-up: real dimensions in the offer

Question for the v2 design: if `offers/create` carries real dimensions (`dx`, `dy`, `dz` in cm on places and/or
on items), does the offer charge the same volumetric step as the calculator? Probe: Moscow and Samara courier
plus Moscow 5post; medium and large; prepaid; insurance on. Variants: (1) v1 shape; (2) places with
`dx/dy/dz`; (3) items with `dx/dy/dz`; (4) both; (5) `predefined_volume` set to the real L x W x H. Cells are the
cheapest offer (truncated), the calculator is the v1 calculator with real dimensions and insurance on (ceil).
Raw in `raw/followup/`. Script: `measure_price.py followup` (38 requests).

| case | calc, real dims, insurance on (v1) | (1) v1 shape | (2) places dx/dy/dz | (3) items dx/dy/dz | (4) both | (5) predefined_volume = real L*W*H |
|---|---|---|---|---|---|---|
| g213-courier-medium-sku_boxes-prepaid-on | 421 | 364 | 420 | 364 | 420 | 364 |
| g213-courier-large-sku_boxes-prepaid-on | 842 | 701 | 841 | 701 | 841 | 701 |
| g51-courier-medium-sku_boxes-prepaid-on | 690 | 577 | 689 | 577 | 689 | 577 |
| g51-courier-large-sku_boxes-prepaid-on | 1532 | 1250 | 1531 | 1250 | 1531 | 1250 |
| g213-pickup_5post-medium-sku_boxes-prepaid-on | 348 | 262 | 347 | 262 | 347 | 262 |
| g213-pickup_5post-large-sku_boxes-prepaid-on | HTTP 400: Dimensions should not exceed limit | 469 | HTTP 400: Dimensions should not exceed limit | 469 | HTTP 400: Dimensions should not exceed limit | 469 |

absent: 403 RUB; explicit 0: 403 RUB

Conclusions:

1. Real `dx/dy/dz` on the **places** make the offer charge the volumetric step. Medium Moscow courier 364 -> 420
   (calculator 421), large 701 -> 841 (842), medium Samara 577 -> 689 (690), 5post medium 262 -> 347 (348). The
   remaining difference is rounding (at most 1 RUB), the same as in the v1 comparison.
2. Real dimensions on the **items** alone change nothing (364 -> 364, 577 -> 577). Adding them on top of the
   places changes nothing either (variant 4 equals variant 2). The step is driven by the places.
3. Variant 5 (`predefined_volume` = real L x W x H) equals the v1 shape exactly. v1 already sends the real volume
   there, and the offer ignores that field. The offer reads the dimension keys on places, not the volume value.
4. Real dimensions block 5post large: with `dx/dy/dz` on places the offer returns "Dimensions should not exceed
   limit" (the same limit the calculator enforces), while the v1 shape is accepted (469 RUB). A v2 that sends real
   dimensions must handle that refusal for 5post.
5. `total_assessed_price: 0` sent explicitly in the calculator gives the same price as an absent field (403 RUB
   both, Moscow courier medium, prepaid, insurance off). The two are interchangeable for pricing.

UNVERIFIED for this follow-up: COD and medium_x3 with places dimensions were not measured (prepaid only, as
briefed); whether a per-unit `places` split with dimensions changes the price for multi-quantity lines; whether
the 5post large refusal also holds for other 5post dimension combinations.
