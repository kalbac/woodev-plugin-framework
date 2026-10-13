# CDEK + Yandex coexistence — rig specs (spec §13 / D9)

Playwright specs for the five acceptance scenarios of the Yandex Delivery v2 design
(`docs-internal/specs/2026-10-12-yandex-v2-plugin-design.md` §13), run against the **live dev rig** — never in CI.
Card: `kalbac/woocommerce-yandex-delivery#18`. Results and defects: the Y15 report of the session that wrote them.

| Spec | Scenario |
|---|---|
| `01-carrier-switching.spec.js` | 1 — CDEK point → Yandex point → CDEK in one classic-checkout session |
| `02-failed-payment.spec.js` | 2 — failed payment, re-checkout with the other carrier on the SAME order |
| `03-orders-pages.spec.js` | 3 — each carrier's orders page shows only its own orders |
| `04-framework-versions.spec.js` | 4 — two framework versions: the newer copy runs |
| `05-deactivation.spec.js` | 5 — deactivate each plugin in turn |
| `06-block-checkout.spec.js` | 1 on the block checkout (block-only gaps are recorded, not release blockers) |

## Rig requirements

- dev rig on `:8973`, **both** `woocommerce-edostavka` (CDEK, `v2`) and `woocommerce-yandex-delivery` active;
- a CDEK pickup and a Yandex pickup (and courier) instance in the zone serving Russia;
- the Yandex token configured for the production contour (`WOODEV_YANDEX_PROD_TOKEN` constant) and a drop-off point in the
  pickup instance's `platform_station`;
- the rig-only helper mu-plugin: `tests/e2e/coexistence/rig/install.sh` (remove with `install.sh remove`). It adds the «Y15 flaky
  gateway» and an equal-rate switch used by scenario 2, both inert until a spec sets their options;
- the specs reach wp-cli through `docker exec` (`WOODEV_E2E_WP_CLI_CONTAINER` overrides the container name).

## Running

```bash
npx playwright test --config=playwright.config.js tests/e2e/coexistence          # all, ~8 min, one worker
WOODEV_E2E_STRICT=1 npx playwright test ... tests/e2e/coexistence                 # known defects run as ordinary assertions
WOODEV_E2E_CDEK_EXPORT=1 npx playwright test ... 05-deactivation.spec.js          # also ONE real CDEK export (see below)
WOODEV_E2E_CDEK_DEACTIVATE=1 npx playwright test ... 05-deactivation.spec.js      # also the two «CDEK deactivated» tests (see below)
node tests/e2e/coexistence/rig/cleanup-orders.js [--dry-run] [--keep=ID,ID]       # delete the orders the specs created
```

Run a scenario's specs with a **long shell timeout or in the background** — scenario 4 alone restarts nothing but rewrites
plugin files, and the checkout flows take 15-50 s each.

## Known defects are annotated, not hidden

The ONE assertion that documents a defect is wrapped in `expectKnownDefect( 'F3', reason, () => expect( … ) )` (`helpers.js`);
every other assertion of the test is ordinary and fails the test normally. While the wrapped assertion fails, the failure is
recorded as a `known-defect` annotation and the test passes; the day it PASSES, the wrapper throws «F3 is FIXED» and the test
turns red — the cue to unwrap it and keep the check. A defect with several facets (F2, F5) gets one wrapper per facet, so fixing
one cannot hide behind another that still fails. Anything that is not an assertion failure (a timeout, a crashed page) is
rethrown, never read as «still broken». The check inside a wrapper must use hard `expect`, not `expect.soft`.
`WOODEV_E2E_STRICT=1` runs the wrapped assertions as ordinary ones so the real messages show.

## What the specs change on the rig, and put back

Every spec restores what it changed in `afterAll`: the Yandex courier instance's `is_enabled` flag (it is switched off while a
point is picked — defect F2), the helper options (`y15_flaky_gateway`, `y15_equal_rates`), plugin activation (scenarios 4 and 5),
and — scenario 4 — a throw-away old-framework copy of the Yandex plugin in the container plus the `WOODEV_FRAMEWORK_DIR` line of
`wp-config.php` (restored byte for byte). A crashed run is recovered by hand with
`wp plugin activate woocommerce-edostavka woocommerce-yandex-delivery`, `rm -rf wp-content/plugins/0-y15-yandex-oldfw`, and
copying `wp-config.php.y15-scenario4` back over `wp-config.php` if it still exists.

The specs never delete their orders (a failure can be inspected). Every order a spec creates is appended to a ledger
(`helpers.js` → `ORDER_LEDGER`, default `$TMPDIR/woodev-y15-coexistence-orders.txt`, override with `WOODEV_E2E_ORDER_LEDGER`);
`rig/cleanup-orders.js` deletes exactly the ids in the ledger — never an e-mail or other pattern match — and spares the ones marked
`keep:` (the order exported to CDEK for real) and any `--keep=` ids.

## Deactivating CDEK is opt-in

CDEK's deactivation removes its remote webhook subscription from the CDEK account, and reactivation does not restore it. So the
two scenario-5 tests that deactivate CDEK (survival of CDEK's data + Yandex orders page, and F7) run only with
`WOODEV_E2E_CDEK_DEACTIVATE=1`; by default they are skipped and a plain run leaves the CDEK account untouched. After an opt-in
run, re-save CDEK's webhook settings (or re-register the subscription) before relying on CDEK webhooks on that rig.

## The real CDEK export

The CDEK production account is a test account: a few calculations and ONE real export are agreed, a courier call never. The
export runs only with `WOODEV_E2E_CDEK_EXPORT=1`, in scenario 5 (Yandex deactivated), on the order that run places. A Yandex
export is not possible — its production billing is inactive, so `offers/confirm` and `request/*` are never sent; scenario 3
«exports» a Yandex order by writing `_yandex_delivery_request_id` and `_yandex_delivery_state_status` directly.
