# Checkout takeover: use `woocommerce_states` for region fields, not client DOM conversion

**Namespace:** `[shipping/checkout]` · **Discovered:** s42 (2026-07-06), browser e2e on `:8888`

## The trap

The §8 checkout field layer's "takeover" (a carrier owns a field for some countries — e.g. its
own region list on `billing_state` for RU/BY/KZ/UZ, which have no native WC states) was first built
as **client-side DOM surgery**: convert WooCommerce's native `billing_state` text `<input>` into our
`<select>`, fetch options via REST, init select2. This is **fundamentally fragile** and loses field
values, because it fights WooCommerce's own checkout machinery:

- WC's `country-select.js` re-renders `billing_state` on country change (and on `update_checkout`),
  wiping our converted element.
- **`update_checkout` (fires on every shipping-method / payment-method / address change) makes WC
  fire PROGRAMMATIC `change` events on address fields** — an empty `''`, or the state wildcard `'*'`
  for a `RU:*`-style base country. A delegated `change` handler that writes every value into an
  external (outside-DOM) store gets **polluted/wiped** by these spurious events.
- Re-initialising an already-enhanced select2 on `updated_checkout` **clears its value**.
- An unconditional "restore store → DOM" on `updated_checkout` **overwrites** a value the field still
  legitimately holds.

Net effect: pick a region → select a shipping method → the region (and city) silently clear → the
order can't be completed. Unit tests + a static code review will NOT catch this — only a real
`update_checkout` cycle in a browser does.

## The fix

**Region (a WC states concept) → let WooCommerce own it natively.** Inject the carrier's regions as
WC states via the **`woocommerce_states`** filter (`Checkout_Handler::inject_states()`), gated by the
same domain `takeover_condition` predicate across `WC()->countries`. WC then renders `billing_state`
as a native `<select>` with our regions **and persists the value in its session** — survives
`update_checkout` with ZERO client DOM surgery. The client adapter must NOT touch state fields
(`isWcManagedField()` → `/(^|_)state$/`).

**City (NOT a WC concept) → stays a client select2**, but its value is made robust by:
1. `initSuggest` **never re-initialises** an already-enhanced select2 (`select2-hidden-accessible`).
2. The `updated_checkout` restore is a **safety net** — restore from the store ONLY when the DOM lost
   the value (`! $field.val()`), never overwrite a value the field still holds.
3. The suggest takeover **re-adds the current value as a selected `<option>`** on conversion.

**External-store delegated `change` guard:** ignore WC's spurious programmatic churn — a change is
"meaningful" only when `event.originalEvent` exists (a real user action) OR the value is non-empty,
AND the value is not `'*'` (WC's any-state wildcard). Otherwise it neither writes the store nor runs
the cascade.

## Rule of thumb

For a checkout field that maps to a WooCommerce concept (states/country), drive it through WC's
native filters (`woocommerce_states`, `woocommerce_default_address_fields`) so WC handles rendering +
session persistence. Only hand-roll client enhancement for fields WC has no concept of (city
autocomplete), and treat the external store as authoritative-but-defensive: WC's re-render fires
lie-y `change('' / '*')` events you must filter, and never re-init select2 or overwrite a live value
on `updated_checkout`.

## Related

- [[classmap-autoload-breaks-class-exists-once-guard]] — another "only a real boot catches it" case.
- Design/impl: `docs-internal/specs|plans/2026-07-06-checkout-field-layer-*`; `Checkout_Handler::inject_states()`,
  `assets/js/frontend/checkout-field-classic.js` (`applyTakeover`/`ensureText`/`initSuggest`/`updated_checkout`).
