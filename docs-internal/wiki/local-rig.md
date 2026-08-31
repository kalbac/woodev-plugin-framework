# The local rig — how it got this way

> Compiled reference. Last compiled: 2026-08-25 (s91, moved out of `CURRENT-STATE.md` when that file
> went over its session-start budget).

`CURRENT-STATE.md` carries the rig's CURRENT values — active provider, field modes, ports, which
branch the tree holds. This article carries the part that does not change from session to session:
why each of these fixtures exists and what breaks if it is removed.

## The pickup-type shipping method, and why it lives outside the repo

- **There IS a pickup-type shipping method on the rig now (s81), and it lives OUTSIDE the repo.** Until s81 the only active method was `Woodev Test Shipping`, whose `delivery_type` is `courier` — so `Checkout_Config::pickup_method_ids()` resolved to `[]` and the entire `hide_for_pickup` branch of the checkout-field policy was physically unreachable on the rig. Fixed with a container-only mu-plugin, `wp-content/mu-plugins/zz-rig-test-pickup-shipping.php` (that directory is NOT bind-mounted from the repo — `zz-rig-yandex-key.php` was already there as precedent), registering `woodev_test_pickup_shipping` (`Woodev Test Pickup`) whose `get_delivery_type()` is `pickup`. It is enabled in zone 1 «Russia» as instance 4, alongside `free_shipping` and `woodev_test_shipping`, so a checkout session can switch between a pickup rate and a courier rate. **Keep it** — it is what made the s80 gap verifiable, and it is the only way to exercise that branch live. To remove: delete the mu-plugin file and `wp wc shipping_zone_method delete 1 4 --user=1`.

### s110 (01.09.2026): the method is now declared in ALL the places the framework asks

Until s110 that mu-plugin declared `get_delivery_type() = 'pickup'` and nothing else, so the rig
had **two half-declared methods and could not express a correct one**:

| method | `is_pickup_shipping()` | pickup button (`Pickup_Field`) | required backstop |
|---|---|---|---|
| `woodev_test_shipping` (fixture) | **false** | yes | yes |
| `woodev_test_pickup_shipping` (mu-plugin) | **true** | no | no |

Choosing either produced a contradiction on screen — a pickup button beside a required address, or
hidden address fields with no way to pick a point — which made #652's scenarios 3 and 4 untestable.

The mu-plugin now also patches the two declarations that ARE reachable through public API after the
fixture builds its handler, on `wp_loaded` priority 20:

- `Checkout_Handler::get_fields()->add( Pickup_Field::create( 'carrier_pickup_point', [ … ] ) )` —
  `add()` is documented as "adds (or replaces)" and is keyed by field id, so re-declaring the slot
  overwrites the fixture's narrower list. Verified: the stored condition spec is now
  `{"state":"chosen_shipping_method","operator":"in","value":["woodev_test_shipping","woodev_test_pickup_shipping"]}`.
- `Checkout_Handler::set_requires_pickup_methods( [ … ] )` — plain public setter.

⚠ **What is deliberately NOT patched, and the limit it leaves.** `Selection_Scope::type_for_method()`
— the fourth declaration — is a PRIVATE constructor argument of `Pickup_Handler` with no setter, and
the fixture hardcodes `woodev_test_shipping`. Consequence: a point chosen under
`woodev_test_pickup_shipping` is **not restored after a page reload**. Choosing a point, seeing the
fields hide and placing the order all work; only remember-across-reload is missing. That gap is
evidence for card **#709** — two of the four declarations are write-once at construction.

`pickup_method_ids()` stays `["woodev_test_pickup_shipping"]`, correctly: it is driven by
`is_pickup_shipping()`, and only the mu-plugin's method genuinely is pickup. `woodev_test_shipping`
keeps its button and stays courier on purpose — it is the contrast case, and the live reproduction
of #709.

⚠ **The mu-plugin is container-only and dies with the volume.** Reinstall by writing the file into
`wp-content/mu-plugins/` — note the cli container needs `docker exec -u root` to write there.

## `woocommerce_checkout_company_field` is `optional`, deliberately

⚠ **Measured `hidden` again on 01.09.2026 (s110)** — someone took the documented revert below, so
the §8 root demo IS dark, exactly as this section predicts: the fields are not on the checkout, and
the operator confirmed it. What is NOT dark is their VALIDATION — `validate()` still enforces their
`required` flag, so the checkout is blocked by two fields nobody can see or fill. That is a
framework defect, not a rig one: card **#708**.

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

## Why the location axes are set the way they are

**The values themselves live in [../CURRENT-STATE.md](../CURRENT-STATE.md), read off the
container. This section is only the WHY**, moved here from that file in s101 when it outgrew its
budget.

The combination left as of 24.08.2026 is **not** the historical default. It is provider
`test-cdek`, region axis «Предустановленный список» (`related-list`), settlement axis «Список с
поиском» (`ajax-select2`) — set deliberately so the operator can exercise the
region-preset + settlement-search pairing, which had never been run live before. The options are
`woodev_location_active_provider`, `woodev_location_field_mode_region`,
`woodev_location_field_mode_settlement`.

Two further values were MEASURED on 26.08.2026 because the s93 handoff had them wrong:
`woodev_location_default_locality_policy` = `fixed` («Москва», `test-cdek:44`) and
`woodev_location_allow_custom_settlement` = `no`. That second one matters in a specific way: with
it `no`, #528's tag row is correctly ABSENT on the rig — and it was read as a regression once
before anyone checked the option. **Read the option, never a doc, before calling a missing tag row
a bug.**

### Switching back to the older default

The older default is provider `dadata` with BOTH axes on `ajax-select2`. Two consequences, both
by design:

- **DaData structurally cannot offer `related-list`** — it is a capability it does not have — so
  switching the provider back silently removes «Предустановленный список» from the region select
  as well.
- A customer record whose level the new provider does not own reads as **ABSENT** (s78): the
  chain empties and the address field locks until the customer re-picks. The record is NOT
  deleted — restoring the provider brings it straight back (verified). If a rig session suddenly
  "loses" its locality, check the active provider before suspecting a bug.

## Which field the live cascade is actually on — measured, s104

`woocommerce_ship_to_destination` on this rig is **`shipping`**, so by Rule 7c the ONE live cascade
sits on the shipping column. In the DOM that means:

| Axis | Live element on this rig |
|---|---|
| region (`related-list`) | `#shipping_state` — a native `<select>` WooCommerce renders |
| settlement (`ajax-select2`) | **`#shipping_city`** — the select2-enhanced one |
| address | `#shipping_address_1` |

`#billing_city` stays a **plain `<input>` with no select2 attached**, which reads exactly like a
broken build if you go looking there first. It is not — it is the non-live column. Confirm with
`jQuery('#shipping_city').data('select2')` before concluding anything about the widget.

**A `<select>` that WooCommerce itself renders is also select2-enhanced** (`#billing_country`,
`#billing_state`, …), so "there is a select2 on the page" proves nothing about OUR widget.

### The empty-list seam

Every message this layer shows the shopper — «nothing found», «source unavailable», and the
widened-scope row from #361 — travels through `options.emptyText` and is therefore **only visible
when the list came back EMPTY**. A search that returns rows shows the rows. When reproducing any of
them, use a query that genuinely matches nothing (`Ззз` works) — otherwise the message is there and
simply not rendered, which looks like the feature is broken.

⚠ `/suggest` answers in **6–10 seconds** here. Wait for the row to appear, not for a timer.

## Docker inventory — what must never be pruned

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume
  `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real
  plugins in one env**, an intentional single instance for testing plugin-to-plugin compatibility.
  **NEVER delete it or its volume, even when the containers show `Exited`.**
- That volume is unattached while the stack is down, so **never run `docker volume prune` or
  `docker system prune --volumes` on this machine** — it would wipe `wordpress-test_db_data`. Clean
  docker only surgically: `docker builder prune`, `docker image prune` (dangling only), and orphans
  you have identified by name.
- Project wp-env = `de59f74e…` (dev `:8973`, tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.
- ⚠ The two wp-env CLI containers are easy to confuse: `…-cli-1` is the DEV rig (`:8973`, carries the
  options), `…-tests-cli-1` is the test stack (`:8974`, deliberately option-free). Reading options
  from the wrong one returns "Does it exist?" for everything.
- ⚠ Writing into `wp-content/mu-plugins/` from the CLI container needs `docker exec -u root`; the
  default user has no write permission there. And `/tmp/...` paths need `MSYS_NO_PATHCONV=1` on this
  Windows shell, or they are rewritten into Windows paths before docker sees them.

## Related

- [../CURRENT-STATE.md](../CURRENT-STATE.md) — the rig's current values
- [rig-pickup-walkthrough.md](rig-pickup-walkthrough.md) — the step order a pickup pass needs
- [../GOTCHAS.md](../GOTCHAS.md) — `rig-checkout-url-is-the-block-checkout`,
  `rig-serves-the-working-tree-branch-switch-reverts-fixes`,
  `a-level-served-can-come-from-the-fallback-not-the-active-provider`,
  `wp-safe-remote-request-local-rig`
