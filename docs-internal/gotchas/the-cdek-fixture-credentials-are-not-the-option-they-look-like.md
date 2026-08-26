# The test-cdek credentials are NOT the option that looks like them

**Namespace:** `[rig/fixtures]` · **Discovered:** s95 (2026-08-26), reproducing a measurement that
had been wrong since s83

## What happens

The rig carries **two** pairs of CDEK credential values, and only one of them is real:

| Where | Read by anything? |
|---|---|
| `woodev_location_cdek_client_id` / `..._secret` (standalone options) | **NO — nothing reads these** |
| `woocommerce_woodev_test_shipping_method_settings['cdek_client_id' / 'cdek_client_secret']` | **YES — this is the credential** |
| `WOODEV_TEST_CDEK_CLIENT_ID` / `..._SECRET` (wp-config constants) | present, but did not gate the failure |

The fixture reads its credentials through
`Woodev_Test_Shipping_Method_Plugin::instance()->get_integration_option( $field_id, '' )`
(`tests/_fixtures/woodev-test-shipping-method/class-test-cdek-location-provider.php:935-937`),
i.e. the **WooCommerce integration settings array** — never the standalone `woodev_location_cdek_*`
options, whose names are the obvious thing to grep for and edit.

So a credentials experiment driven through `wp option update woodev_location_cdek_client_id ...`
changes nothing at all, and the provider keeps answering normally. The experiment reads as
"the provider does not fail on bad keys" when in fact **bad keys were never installed**.

## How it bit

`#405` shipped the honest-failure contract in s83: `token()` now THROWS
`Location_Provider_Exception` when `is_configured()` is `true` but the exchange fails, instead of
degrading to `''` and being indistinguishable from "no such city". The card was closed as completed.

But CURRENT-STATE carried, from s83 until s95, the note that the fix could not be demonstrated:

> With a deliberately bogus CDEK client id — confirmed in wp-config, transient cleared, measured
> against a control — the provider returned the same results as with valid keys and never threw.

That measurement was defeated by editing the decoy option. Two further things masked it:

- **The token transient short-circuits everything.** `token()` returns a cached
  `woodev_test_cdek_token` before it ever looks at the credentials, so a stale valid token keeps the
  provider working no matter what the keys say.
- **The REGION level never touches the network while its dictionary is warm.**
  `regions()` caches into `woodev_test_cdek_regions_{country}`
  (`class-test-cdek-location-provider.php:685-702`), and region suggest is a local substring match
  over that dictionary. On a rig whose region axis is «Предустановленный список» (`related-list`),
  region browsing **cannot demonstrate a credential failure at all.**

## The measurement that actually works (s95, rig `:8973`, provider `test-cdek`)

```bash
C=de59f74e6d3d19d18a7f7b6608fda7e7-cli-1
# install a bad key where the fixture really reads it:
docker exec $C wp option patch update woocommerce_woodev_test_shipping_method_settings cdek_client_id "BOGUS"
docker exec $C wp transient delete woodev_test_cdek_token          # else a cached token hides it
docker exec $C wp transient delete woodev_test_cdek_regions_ru     # else REGION answers from cache
```

Measured, with a valid-key control before and after:

| Condition | region `Мос` | settlement `Зеленоград` |
|---|---|---|
| valid keys, token transient cleared | OK, 2 results | OK, 5 results |
| bogus key in the **decoy option**, both transients cleared | OK, 2 results | OK, 5 results |
| bogus key in the **integration settings**, regions transient warm | OK, 2 results | **THREW** `Location_Provider_Exception` |
| bogus key in the **integration settings**, both transients cleared | **THREW** | **THREW** |

`#405`'s contract is therefore **correct and demonstrable**. It was the measurement that was broken,
not the code.

## The rule

- **Before concluding "the provider does not fail", prove the bad credential is actually in force.**
  Read it back through the same accessor the provider uses (`get_integration_option()`), not through
  the option whose name matches. A control run is not enough — both runs can be running the same
  valid key.
- **Clear both transients**, not just the token one.
- **Drive the SETTLEMENT level.** Region is a cached local dictionary and proves nothing about
  connectivity or credentials.
- The standalone `woodev_location_cdek_*` options are dead weight on the rig. Do not trust their
  values and do not edit them expecting an effect.

## Related

- [wp-safe-remote-request-local-rig](wp-safe-remote-request-local-rig.md) — the other rig traps
  around outbound HTTP
- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
  — the other way a rig measurement silently measures the wrong thing
- [the-three-location-field-modes-and-their-russian-labels](the-three-location-field-modes-and-their-russian-labels.md)
  — why the rig's region axis is `related-list`, which is what makes the region level useless here
