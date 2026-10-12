# Yandex terminals (market_l4g) and 5Post: parcel weight and size limits

Question (plugin card kalbac/woocommerce-yandex-delivery#1, spec §5.6–5.7): above which parcel weight and
dimensions does `pricing-calculator` refuse a terminal (postamat) of the `market_l4g` network, and above which
does it refuse a `5post` point? Which refusal text appears? Input for the static pre-filter (card Y6/Y7).

## Answer

Both networks refuse on three measured limits; each boundary is an exact integer step (inclusive values measured
on both sides).

| Network (point measured) | Max weight | Max longest side | Max sum of sides (dx+dy+dz) |
|---|---|---|---|
| **terminal**, market_l4g (Samara, `6-я просека 143`) | **20 kg** (20000 g pass, 20001 g refused) | **40 cm** (40 pass, 41 refused) | **118 cm** (40×40×38 pass, 40×40×39 refused) |
| **5post** (Moscow, `Боровское ш 62к3`) | **15 kg** (15000 g pass, 15001 g refused) | **70 cm** (70 pass, 71 refused) | **136 cm** (46×45×45 pass, 46×46×45 refused) |
| control: market_l4g `pickup_point` (Moscow) | not reached | not reached | not reached: 60×40×40 at 15 kg accepted (618.54 RUB) |

- **Terminals are not in Moscow.** The Moscow geo sample holds 2276 points, all `pickup_point`, and no
  `terminal` (`../2026-10-12-yandex-geo-measurement/tables.md`; two `terminal` lists for geo 213 returned 0 points).
  The terminal measured is the single market_l4g terminal of geo 51 (Samara). Brief asked for Moscow; this is the
  deviation.
- **Refusal texts.** Both networks use `dimensions_limit` «Dimensions should not exceed limit» for the weight limit
  too (terminal 20001 g, 5post 15001 g) and for 5post's side and sum limits. The terminal returns
  `no_delivery_options` «No delivery options for interval» for every other size refusal it gave (a 41–60 cm side,
  40×40×39 and up, 45×45×45), and `dimensions_limit` only for 60×40×40 and for weight above 20 kg. So the text does
  not reliably say which limit was hit: a fallback must not infer the cause from the text.
- **Sum rule.** The sum of sides fits every measured case; volume and girth do not (see Results, §3). On the
  terminal, within sides ≤ 40 cm, sum ≤ 118 and volume ≤ ~61,000 cm³ cut the same boxes (see UNVERIFIED).
- **Axes combine independently in the measured space.** The near-limit boxes pass at the weight limit too:
  40×40×38 at 20 kg on the terminal, 46×45×45 at 15 kg on 5post.
- **Proposed constants for Y6/Y7** (§5.7 static pre-filter, grams and cm, inclusive):

```php
// Yandex parcel envelope, measured 12.10.2026 (docs-internal/research/2026-10-12-yandex-terminal-limits).
const TERMINAL_MAX_WEIGHT_G  = 20000; // market_l4g terminal, inclusive
const TERMINAL_MAX_SIDE_CM   = 40;    // longest side, inclusive
const TERMINAL_MAX_SUM_CM    = 118;   // dx + dy + dz, inclusive
const FIVE_POST_MAX_WEIGHT_G = 15000; // 5post point, inclusive
const FIVE_POST_MAX_SIDE_CM  = 70;    // longest side, inclusive
const FIVE_POST_MAX_SUM_CM   = 136;   // dx + dy + dz, inclusive
```

  Keep the §5.7 fallback too: a 400 with either `dimensions_limit` or `no_delivery_options` on a candidate network
  removes that network for this parcel. Do not treat `no_delivery_options` as proof of a size cause on its own: it
  is also what s174 saw for a 240 cm courier box.

## Method

- Calculator body built as the s174 followup did (`../2026-10-12-yandex-price-measurement/followup.md`): one place,
  `physical_dims {weight_gross (g), dx, dy, dz (cm)}`, `total_weight` = the same grams, `payment_method`
  `already_paid`, `total_assessed_price` 300000 kop (3000 RUB, insurance on), `tariff` `self_pickup`, source the
  s174 drop-off point `20cfe81c-bb45-4cfa-85e6-31fdade8ed23` (Москва Трубная площадь 2, market_l4g).
- Weight axis: 10×10×10 cm box, start 15000 g, doubling up or halving down, then bisection to 250 g.
- Length axis: 500 g parcel with 10×10 cm cross-section, start 60 cm, ×1.5 steps, then bisection to 1 cm.
- Sum and volume: boxes inside the longest-side limit, plus one family 40×40×c (c = 10…40) to bisect the sum.
- Each refusal recorded with its code and text. Only two codes occurred (`dimensions_limit`, `no_delivery_options`);
  any other refusal would have stopped the run for a manual read.
- Points calls (4 of 4): `geo 213 terminal` (no filter) and `geo 213 terminal already_paid` (both 0 points);
  `geo 51 terminal` (1 point, market_l4g, `already_paid` accepted); `geo 213 pickup_point already_paid` (confirms the
  s174 5post point and the market_l4g control are still listed).
- Calculator calls: 56 of the budget of 60. No `offers/*`, no `request/*`.
- Throttle 0.5 s; no 429 among the recorded responses (a retried 429 would not appear in `raw/`).

## Results

### 1. Weight (10×10×10 cm, one place, insurance on)

| Network | pass (g) | refused (g) | Refusal text |
|---|---|---|---|
| terminal | 15000, 18750, 19500, 20000 | 20001, 20250, 20500, 22500, 30000 | `dimensions_limit` |
| 5post | 15000 | 15001, 15250, 15500, 16000, 17000, 18750, 22500, 30000 | `dimensions_limit` |

Boundaries: terminal 20000 / 20001 g; 5post 15000 / 15001 g. Inclusive.

### 2. Longest side (500 g, 10×10 cross-section)

| Network | pass (cm) | refused (cm) | Refusal text |
|---|---|---|---|
| terminal | 40 | 41, 42, 45, 50, 60 | 41–60: `no_delivery_options` |
| 5post | 60, 68, 70 | 71, 72, 75, 90 | `dimensions_limit` |

### 3. Sum of sides and volume

| Box (cm) | Sum | Volume (cm³) | Network | Result |
|---|---|---|---|---|
| 40×10×10 | 60 | 4000 | terminal | pass |
| 41×10×10 | 61 | 4100 | terminal | refused `no_delivery_options` |
| 20×20×20 | 60 | 8000 | terminal | pass |
| 30×30×2 | 62 | 1800 | terminal | pass |
| 40×20×20 | 80 | 16000 | terminal | pass |
| 40×40×10 | 90 | 16000 | terminal | pass |
| 40×40×25 | 105 | 40000 | terminal | pass |
| 40×40×32 | 112 | 51200 | terminal | pass |
| 40×40×36 | 116 | 57600 | terminal | pass |
| **40×40×38** | **118** | **60800** | terminal | **pass** (also at 20 kg, see §4) |
| **40×40×39** | **119** | **62400** | terminal | **refused `no_delivery_options`** |
| 40×40×40 | 120 | 64000 | terminal | refused `no_delivery_options` |
| 45×45×45 | 135 | 91125 | terminal | refused `no_delivery_options` |
| 60×20×20 | 100 | 24000 | terminal | refused `no_delivery_options` (length) |
| 60×40×40 | 140 | 96000 | terminal | refused `dimensions_limit` (500 g and 15 kg) |
| 45×45×45 | 135 | 91125 | 5post | pass |
| 46×45×45 | 136 | 93150 | 5post | pass (also at 15 kg, see §4) |
| 46×46×45 | 137 | 95220 | 5post | refused `dimensions_limit` |
| 68×35×35 | 138 | 83300 | 5post | refused `dimensions_limit` |
| 70×35×35 | 140 | 85750 | 5post | refused `dimensions_limit` |
| 60×40×40 | 140 | 96000 | 5post | refused `dimensions_limit` (500 g and 15 kg) |
| 60×20×20 | 100 | 24000 | 5post | pass |

Reading:
- **5post volume is ruled out:** 68×35×35 (volume 83300) is refused while 45×45×45 (91125) passes.
- **5post girth is ruled out:** with girth = a + 2(b + c), 68×35×35 gives 208 (refused) and 46×45×45 gives 226
  (pass). So the sum of sides (136 / 137) is the simplest rule that fits all rows.
- **Terminal:** sum and volume cannot be separated with sides ≤ 40 cm. Any such box with sum ≤ 118 has volume
  ≤ 60840 cm³, and sum 119 forces 40×40×39 (62400). So "sum ≤ 118" and "volume ≤ ~61,000 cm³" make the same
  decision on every box the terminal can accept.

### 4. Combined axes (near-limit size at the weight limit)

| Network | Box (cm) | Weight | Result |
|---|---|---|---|
| terminal | 40×40×38 | 20000 g | pass, 1252.94 RUB (885.72 RUB at 500 g) |
| 5post | 46×45×45 | 15000 g | pass, 469.7 RUB (same price at 500 g) |

### 5. Control: market_l4g pickup_point (Moscow, `Волгоградский 10 стр2`, prepaid)

| Box (cm) | Weight | Result |
|---|---|---|
| 60×40×40 | 15000 g | pass, 618.54 RUB |

The 60×40×40 / 15 kg parcel refused by terminal and 5post is accepted by a plain pickup point, so the refusal is
per network, not a property of the parcel.

## UNVERIFIED

- **Terminal outside Samara.** One terminal measured (geo 51). The brief asked for Moscow; Moscow has no terminal.
  The limits are assumed to be the same for every market_l4g terminal.
- **One point per network.** Yandex may set limits per point (cell sizes; spec §8 notes the same question).
- **Sum vs volume for the terminal** is not separable inside sides ≤ 40 cm (see §3). Outside that region (a 60 cm
  side) the length rule already refuses.
- **Girth and volume for 5post** are ruled out only against the rows above; a rule that mixes sum and longest side
  is not tested.
- **Exact sum threshold tested with one side at the limit.** 136 / 137 were measured on 46×45×45 / 46×46×45 only;
  the sum edge for other shapes is assumed.
- **Weight tested with a 10×10×10 box and with two near-limit boxes** (40×40×38 terminal, 46×45×45 5post). Other
  combinations are assumed to pass.
- **Cross-section 10×10 cm** for the length axis; the terminal's 40 cm pass with 40×40 cross-sections (§3) confirms
  the length edge only with large cross-sections.
- **Prepaid only.** COD (`card_on_receipt`) limits are not measured. A COD terminal may differ.
- **Insurance on** (3000 RUB) in every probe. Insurance-off behaviour is not measured.
- **Single source** (Moscow drop-off). Limits from other sources are assumed the same.
- **`no_delivery_options` is not specific.** It is seen for size beyond the terminal's envelope here and for a 240 cm
  courier box in s174; it may also carry other causes (interval, route) that were not separated.

## Files

- `measure_limits.py`: `discover` (points) and `run` (probes). Token read at runtime from `.wp-env.override.json`,
  never written to a file.
- `raw/calc/`: 56 pricing-calculator request/response pairs (body, HTTP status, response; no headers, no token).
- `raw/points/`: 4 pickup-points/list responses (full, token-free).
- `raw/ctx.json`: the points used (id, operator, type, address, payment methods).
- `results.json`: bracket and probe results, plus call totals (`calc_calls_total` 56, `points_calls_total` 4).
- `run.log`: console output of the final run (all probes answered from the cache, 0 new calls).

## Related

- [Spec: Yandex v2 plugin design](../../specs/2026-10-12-yandex-v2-plugin-design.md) §5.6 («Oversized»), §5.7
  (envelope for terminals / 5post), §6.1 (pickup-points filters).
- [s174 price measurement](../2026-10-12-yandex-price-measurement/README.md) (the 5post refusal, the calculator body
  shape, the `followup.md` table).
- [s174 geo measurement](../2026-10-12-yandex-geo-measurement/README.md) (point counts by type per geo; Moscow has
  no terminal).
