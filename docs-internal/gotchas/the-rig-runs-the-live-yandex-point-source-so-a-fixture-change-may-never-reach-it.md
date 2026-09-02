# The rig runs the LIVE Yandex point source — a fixture change may never reach it

**Namespace:** `[rig/*]` · **Measured:** s112 (02.09.2026), verifying #270 and trying to reach #150.
**Extended:** s114 (03.09.2026) — the live source ignores the requested locality entirely (#747), which settles #744.

## The trap

`Woodev_Test_Bulk_Point_Source` is the obvious fixture to edit when you want the rig to show
different pickup points. Card #270 taught it three localities, its unit tests went green, and a
direct call proves it works:

| locality asked | points returned |
|---|---|
| Москва / Moscow | 52 |
| Санкт-Петербург | 4 |
| Краснодар | **1** |
| Урюпинск (unknown) | 0 |

**And none of it reaches the rig**, because the rig does not use that source. `wp-config.php` in the
dev container carries:

```php
define( 'WOODEV_TEST_PICKUP_LIVE_YANDEX', true );
define( 'WOODEV_TEST_PICKUP_STRATEGY', 'viewport' );
```

and in `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php` the LIVE flag
**wins over everything below it** — ahead of `WOODEV_TEST_PICKUP_LIVE_POCHTA`, and ahead of
`WOODEV_TEST_PICKUP_STRATEGY` entirely:

```php
if ( WOODEV_TEST_PICKUP_LIVE_YANDEX ) {
    $point_source = new \Woodev_Test_Live_Yandex_Point_Source();
} elseif ( WOODEV_TEST_PICKUP_LIVE_POCHTA ) {
    ...
} else {
    $point_source = ( $viewport_strategy === WOODEV_TEST_PICKUP_STRATEGY ) ? ... : new \Woodev_Test_Bulk_Point_Source();
}
```

So the modal shows **300 real Yandex.Market points around Moscow**, not the 52 fixture ones. Reading
`WOODEV_TEST_PICKUP_STRATEGY` alone tells you nothing — it is dead while the LIVE flag is on.

## Why it is easy to get wrong

Two different sources both answer "Москва" and only "Москва", for two unrelated reasons:

- the **fixture bulk** source gates on its own canonical locality list;
- the **live Yandex** source has its own `FIXTURE_LOCALITY` (`class-test-live-yandex-point-source.php`)
  and, being viewport-strategy, also answers by map bounding box.

An empty modal therefore has at least two possible causes, and the sentence "all the fixture points
are in Moscow, so any other locality honestly says «no points»" — true of the fixture — is **not**
the explanation for what the rig actually does.

## ✅ Before concluding anything about pickup points on the rig

```bash
docker exec <dev-cli> wp config get WOODEV_TEST_PICKUP_LIVE_YANDEX --type=constant
docker exec <dev-cli> wp config get WOODEV_TEST_PICKUP_STRATEGY   --type=constant
```

`1` for the first means the fixture sources are both bypassed. To exercise a fixture change:

```bash
wp config set WOODEV_TEST_PICKUP_LIVE_YANDEX false --raw --type=constant
wp config set WOODEV_TEST_PICKUP_STRATEGY   bulk          --type=constant
```

⚠ **Put them back to `true` / `viewport` afterwards** — that is the rig's standard state, and a
`define()` left flipped is invisible to the next session. Confirm by reopening the modal: the
standard rig shows the Yandex map with clustered Moscow points.

## The live source ignores the requested locality ENTIRELY (measured s114)

The trap above is about which source runs. This one is about what that source then does, and it is
the sharper of the two: **the live Yandex source does not look at the requested locality at all.**
Measured through the real REST route with `rest_do_request()`, comparing point **id sets**, not
counts:

| `locality` sent to carrier 1 | points |
|---|---|
| `Москва` | 815 |
| `Краснодар` | 815 |
| `test-cdek:44` (a KEY) | 815 |
| `ЭтогоГородаНетНигде` (nonsense) | **815** |

All four sets are identical. So any rig check shaped as *"I picked city X — did X's points come
back?"* passes through carrier 1 **even for a city that does not exist**. It looks like a
verification and verifies nothing. Two conclusions have already been built on it, and both were
wrong (the s112 measurement, and the "the locality is lost between the checkout and the source"
reading it produced).

Two more facts from the same measurement:

- **The source's real strategy is `bulk`, though `WOODEV_TEST_PICKUP_STRATEGY` reads `viewport`.**
  The constant is dead while the LIVE flag is on (see above), and the live source declares `bulk`
  itself. That is why a `bbox`-addressed query to carrier 1 returns 0 — a strategy mismatch refused
  in `Pickup_Controller::query_matches_strategy()`, not a defect.
- **The point count is live data and moves.** This file said ~300 in s112; s114 measured 815. Never
  pin a test or a conclusion to that number.

### And once the layer is wired, the `locality` VALUE is inert on BOTH carriers

Measured again after #746 landed (same session), because that fix wired the second carrier's
`Pickup_Handler` to its plugin and thereby changed what this measurement means:

| `locality` sent to carrier 2 | before #746 | after #746 |
|---|---|---|
| `Москва` | 3 | 3 |
| `Краснодар` | **1** | **3** |
| `test-cdek:44` (a KEY) | **0** | **3** |
| `ЭтогоГородаНетНигде` | 0 | **3** |

Nothing is broken — this is the #159 contract working. With a plugin wired, the server resolves the
customer's OWN record (`Location_Service::get_customer_record()`) and hands it to the source;
`Point_Source` implementations prefer that record over the bare `locality` string, which exists only
as the pre-#159 fallback. On this rig the default-locality policy is `fixed`, so even a GUEST gets
`test-cdek:44` (Москва, `implicit=true`) — hence 3 points for every string, nonsense included.

**So the parameter's PRESENCE and its VALUE do completely different jobs:**

- **presence** gates `Point_Query::from_request()`. Absent (or `''`, with no valid `bbox`) → the empty
  list, before the source is ever asked. This is the half that diagnoses an empty modal.
- **value** is ignored whenever a record is attached — i.e. always, on a wired carrier.

✅ **To measure which city's points come back, change the CUSTOMER'S RECORD, never the URL.** Drive
the cascade's `/select`, or write `woodev_customer_location` / the default-locality option. Passing a
different `?locality=` proves nothing on either carrier: carrier 1's source ignores it, and carrier
2's record outranks it.

## What this settles: #744, and the shape of an empty answer

The section this replaces asked why, with the bulk source and the customer in Краснодар, the
endpoint answered `{"points":[]}`. Settled in s114 — **not a product defect.**
`Pickup_Controller::get_points_data()` returns the empty list **before the source is ever asked**
whenever `Point_Query::from_request()` yields `null` (no `locality` AND no valid `bbox`, or
`locality=''`) or the strategy does not match. `attach_location_context()` runs only *after* that
gate, so the customer's stored record **cannot rescue an unaddressed request**. With any locality
present, no carrier ever answers empty. An empty list therefore means the request carried no
locality — the region/settlement chain had not resolved (gotcha
`the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field`).

## How it was resolved (s112, later the same day)

The operator's decision was NOT to flip constants by hand and NOT to add a filter to the framework
(an earlier proposal of mine, withdrawn as treating a rig problem with product code). Instead an
EXISTING fixture — `woodev-realistic-shipping-plugin` — was given its own pickup layer, so the rig
now runs **two carriers side by side**, each with its own `Point_Source`, its own
`Pickup_Handler` and its own route:

| method | source | route |
|---|---|---|
| `woodev_test_shipping` | live Yandex, ~300 Moscow points | `/pickup/woodev-test-shipping-method/points` |
| `woodev_realistic_pickup_shipping` | static fixture: Москва 3, **Краснодар 1** | `/pickup/woodev-realistic-shipping/points` |

So the constants below no longer need flipping to reach static data, and **#150 is testable at
last** — Краснодар is the single-point city it always needed. The constant precedence documented
here is still true and still worth knowing; it is simply no longer the only way in.

Traps met while doing it: gotcha
`standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see`.

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — the other "the rig is not what you assume" trap
- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the rig serves the working tree
- [the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field](the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md) — why a request goes out with no locality at all
- [../wiki/local-rig.md](../wiki/local-rig.md) — fixture and option history
