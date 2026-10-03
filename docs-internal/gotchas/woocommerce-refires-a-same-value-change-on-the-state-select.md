# gotcha: WooCommerce re-fires `change` on the state select with the SAME value — on page load and on every calculator toggle

**Namespace:** `[shipping/checkout]`
**Discovered:** s149 (2026-10-03), card #331 (classic cart shipping calculator)

## What happened

The location cascade was reused in the classic cart's shipping calculator. Measured on the rig with a real browser:

- a plain cart page load sent `POST /location/select` with no customer action;
- re-opening the calculator («Change address») sent another `/select` and **emptied the city** the customer had picked.

The classic checkout page load sends no `/select` (measured on `main` the same hour), so the worker's first explanation — «the
checkout does the same» — was an inference from code and was wrong.

Cause: WooCommerce's `country-select.js` / `cart.js` rebuild the state `<select>` and trigger `change` with the value it already had —
on load (`wc_address_i18n_ready` → `country_to_state_changed`) and on every calculator toggle. Two of our handlers read that as a
customer region change:

1. `attachRelatedListRegion()` starts with `lastHandledText = null`, so the first same-value `change` looked like a pick → `onSelect()`
   → `/select`.
2. `handleFieldChanged()` compared the remembered region TEXT («Москва», written by the backwards fill) with the rebuilt option's
   VALUE (the WC code `МОСКВА`) → «real transition» → `clearDescendants()` wiped the city.

## ❌ Wrong

```js
// any `change` on the state select = the customer changed the region
$state.on( 'change', function() { onSelect( this.value ); clearDescendants(); } );
```

## ✅ Correct

Seed the handler with the option selected at attach time and judge identity by the option **value** (the WC state code); use the text
only as the bridge for an option our own back-fill created (value === text). A same-value `change` is a no-op; a different value is a
transition even under an identical label. In the framework this is scoped to the cart (`CART_CONTEXT`, `seedSelectedText`) — the
checkout never showed the symptom.

## Related

- [js-hidden-checkout-field-is-still-required-server-side](js-hidden-checkout-field-is-still-required-server-side.md) — another place where WooCommerce's own field machinery and ours disagree
- `../gotcha-index/shipping-checkout.md`
