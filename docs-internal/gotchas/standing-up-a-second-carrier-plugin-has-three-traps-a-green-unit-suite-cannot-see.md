# Standing up a SECOND carrier plugin has three traps a green unit suite cannot see

**Namespace:** `[rig/fixtures]` · **Measured:** s112 (02.09.2026), card #734 / PR #735, while giving
`woodev-realistic-shipping-plugin` its own pickup layer so the rig could show two carriers at once.

## Why it matters beyond the rig

Several carrier plugins on one shop is the ORDINARY production arrangement — the whole bootstrap and
resolver exist for it. The rig had never reproduced it, so every one of these was invisible until a
second carrier actually booted.

## Trap 1 — a fixture driven only by PHPUnit never registers itself

`woodev-realistic-shipping-plugin` declared a loader definition and an init callback, and **nothing
called `register_loader_definition()`**: the unit test calls it itself. On a real WordPress the
plugin activated, loaded, and its shipping methods **did not exist at all** — `WC()->shipping()->get_shipping_methods()`
simply did not list them, with no error anywhere.

✅ A fixture that must work on the rig needs the same registration preamble the shipped fixtures
have (probe `method_exists( $bootstrap, 'register_loader_definition' )`, then call it).

## Trap 2 — `load_updater()` fatals on any fixture that no-ops its license handler

`Woodev_Plugin` hooks `load_updater()` on `init`, and that method does:

```php
$license_key = $this->get_license_instance()->get_license();
```

A fixture that overrides `init_license_handler()` as a no-op — which every isolated fixture here
does — has no license instance, so **every admin, cron and WP-CLI request dies**:

```text
Fatal error: Uncaught Error: Call to a member function get_license() on null
  in .../woodev/class-plugin.php:472
```

It took the rig down the moment the fixture was booted by a real WordPress for the first time. The
unit suite never fires `init`, so it stayed green throughout.

✅ No-op `load_updater()` too, alongside the other no-op subsystems.

## Trap 3 — two carriers must not share a checkout FIELD id

Both carriers declared `carrier_pickup_point`. `woocommerce_checkout_fields` is keyed by field id,
so the two injections collapse into **one** field — belonging to whichever handler ran last — and
the other carrier's pickup button **silently never renders**. No warning, no console error; the
button is simply absent.

✅ Each carrier owns its own field id (`carrier_pickup_point` vs `realistic_pickup_point`). The
framework already anticipates the multi-carrier case elsewhere — `Pickup_Handler`'s nonce-node
docblock says "two shipping plugins on one checkout page get two distinct nodes instead of fighting
over one" — the field id is the piece that has no such derivation.

## And one consequence in the test suite

`tests/e2e/checkout-pickup.spec.js` located the button as a bare `button.woodev-pickup-trigger`,
which had been unambiguous only because the rig ran one carrier. With two it matches both and
Playwright fails the assertion with a **strict-mode violation** rather than anything readable. The
locator is now scoped to the slot of the carrier the walkthrough is about; the slot container id is
`#woodev-pickup-slot-{field_id}-review`, which is what makes carriers distinguishable in the DOM.

## What the second carrier immediately found

A real product defect, within minutes of existing: the #709 pickup-declaration reconciliation
compares the FLEET-wide `Checkout_Config::pickup_method_ids()` against ONE handler's own list and
demands equality, so it fires a false `_doing_it_wrong()` for every carrier on any multi-plugin
shop. Card **#736**. That is the argument for keeping two carriers on the rig.

## Related

- [the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it](the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it.md) — why a second carrier was needed at all
- [file-deletion-tail-includes-classmap-fixtures](file-deletion-tail-includes-classmap-fixtures.md) — the other "wiring the tests never execute" trap
- [fixture-classes-must-live-inside-plugin-init](fixture-classes-must-live-inside-plugin-init.md) — a sibling fixture-loading rule
