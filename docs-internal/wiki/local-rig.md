# The local rig — how it got this way

> Compiled reference. Last compiled: 2026-08-25 (s91, moved out of `CURRENT-STATE.md` when that file
> went over its session-start budget).

`CURRENT-STATE.md` carries the rig's CURRENT values — active provider, field modes, ports, which
branch the tree holds. This article carries the part that does not change from session to session:
why each of these fixtures exists and what breaks if it is removed.

## The pickup-type shipping method, and why it lives outside the repo

- **There IS a pickup-type shipping method on the rig now (s81), and it lives OUTSIDE the repo.** Until s81 the only active method was `Woodev Test Shipping`, whose `delivery_type` is `courier` — so `Checkout_Config::pickup_method_ids()` resolved to `[]` and the entire `hide_for_pickup` branch of the checkout-field policy was physically unreachable on the rig. Fixed with a container-only mu-plugin, `wp-content/mu-plugins/zz-rig-test-pickup-shipping.php` (that directory is NOT bind-mounted from the repo — `zz-rig-yandex-key.php` was already there as precedent), registering `woodev_test_pickup_shipping` (`Woodev Test Pickup`) whose `get_delivery_type()` is `pickup`. It is enabled in zone 1 «Russia» as instance 4, alongside `free_shipping` and `woodev_test_shipping`, so a checkout session can switch between a pickup rate and a courier rate. **Keep it** — it is what made the s80 gap verifiable, and it is the only way to exercise that branch live. To remove: delete the mu-plugin file and `wp wc shipping_zone_method delete 1 4 --user=1`.

## `woocommerce_checkout_company_field` is `optional`, deliberately

- **`woocommerce_checkout_company_field` was flipped `hidden` → `optional` on the rig
  (24.08.2026).** The §8 demo moved onto `billing_company`/`billing_address_2` (#481), and
  `billing_company` is a field WooCommerce REMOVES from the checkout array entirely when that
  setting is `hidden` — measured: with it hidden the id was absent even with the customer country
  set to RU, so the demo had nothing to take over and was invisible on the rig. Revert with
  `wp option update woocommerce_checkout_company_field hidden`, but the §8 root demo then goes dark
  again. Note that both demo fields keep WooCommerce's OWN labels server-side (`Company name`,
  `Apartment, suite, unit, etc.`): a takeover field is converted CLIENT-side by
  `checkout-field-classic.js`, and `inject()` deliberately leaves WC's entry alone
  (`test_inject_leaves_takeover_fields_to_woocommerce` asserts exactly that). Do not read the
  native label as "the demo is not working".

## Two live location providers

- **Two live location providers on the rig now (s76).** DaData is active by default; the CDEK test
  contour is registered as `test-cdek` (fixture
  `tests/_fixtures/woodev-test-shipping-method/class-test-cdek-location-provider.php`) and its
  credentials sit in the container's wp-config as `WOODEV_TEST_CDEK_CLIENT_ID` /
  `WOODEV_TEST_CDEK_CLIENT_SECRET`. Flip with
  `wp option update woodev_location_active_provider test-cdek` (back: `dadata`). CDEK serves
  region+settlement only, so with DaData also configured the address level falls back to DaData —
  that is the layer answering honestly, not a bug (gotcha
  `a-level-served-can-come-from-the-fallback-not-the-active-provider`).

## The live-Yandex bulk switch on `:8973`

- **dev `:8973` — LIVE YANDEX bulk ON.** `WOODEV_TEST_PICKUP_LIVE_YANDEX=1` wins over `WOODEV_TEST_PICKUP_LIVE_POCHTA=false` and `WOODEV_TEST_PICKUP_STRATEGY=viewport`; the rig serves 812 live Yandex points (Moscow). The DaData token and `clean_secret` are both configured. Fixture is active only when both live flags are false. `WOODEV_TEST_POCHTA_ACCOUNT_ID` / `WOODEV_TEST_POCHTA_ACCOUNT_TYPE` (operator-supplied Отправка credentials — never committed) let `WOODEV_TEST_PICKUP_EMBEDDED=1` drive the live Почта widget; that switch is currently OFF.

## Related

- [../CURRENT-STATE.md](../CURRENT-STATE.md) — the rig's current values
- [rig-pickup-walkthrough.md](rig-pickup-walkthrough.md) — the step order a pickup pass needs
- [../GOTCHAS.md](../GOTCHAS.md) — `rig-checkout-url-is-the-block-checkout`,
  `rig-serves-the-working-tree-branch-switch-reverts-fixes`,
  `a-level-served-can-come-from-the-fallback-not-the-active-provider`,
  `wp-safe-remote-request-local-rig`
