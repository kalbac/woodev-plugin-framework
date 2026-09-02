# The rig runs the LIVE Yandex point source — a fixture change may never reach it

**Namespace:** `[rig/*]` · **Measured:** s112 (02.09.2026), verifying #270 and trying to reach #150.

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

## One thing this did NOT settle

With the bulk source active and the customer in Краснодар, the points endpoint answered
`{"points":[]}` even though the source returns one point for that name when called directly. The
locality is lost somewhere between the checkout and the source. Not diagnosed — card **#734**.

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
- [../wiki/local-rig.md](../wiki/local-rig.md) — fixture and option history
