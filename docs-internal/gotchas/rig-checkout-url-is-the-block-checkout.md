# The rig's `/checkout/` is the BLOCK checkout — the picker lives on `/classic-checkout/`

**Namespace:** `[rig/browser]`
**Discovered:** 2026-08-11 (s65)

## The trap

`http://localhost:8973/checkout/` on the project rig renders WooCommerce's **block** checkout.
The pickup picker is a classic-checkout feature — the block adapter is SP-11, still unbuilt — so
on that page there is no `form.checkout`, no `carrier_pickup_point` field and no trigger button.

Probing it returns a perfectly plausible "the feature is not there":

```json
{ "hasCheckoutForm": false, "fieldPresent": false, "methods": [] }
```

which reads as a broken build rather than the wrong URL. The page title is `Checkout`, it lists
shipping options, and it takes an order — everything looks right.

## Correct

Use **`http://localhost:8973/classic-checkout/`** (page id 13, slug `classic-checkout`). There is
a matching `classic-cart` (id 14). Sanity check after loading:

```js
{ hasCheckoutForm: !!document.querySelector( 'form.checkout' ) }   // must be true
```

## Also worth knowing on this rig

- A product exists as id **12** (`E2E Test Product`); `?add-to-cart=12` fills the cart.
- The billing state (`billing_state`) is a WC states select carrying `77`/`78`/`23`; the city
  (`billing_city`) is a dependent suggest whose options are empty on load, so its value renders
  blank after a reload even when the customer picked one. Set it with jQuery plus an injected
  `<option>`, per the standing "set the city by hand or the picker is empty" trap.
- `npx wp-env run cli wp post list` from the repo root is the reliable way to find these pages;
  it takes 15–120 s, which is the rig being slow, not a hang.

## Related
- [[rig-serves-the-working-tree-branch-switch-reverts-fixes]]
- [[wpenv-resolves-environment-from-cwd]]
