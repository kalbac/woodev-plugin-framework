# `extensionCartUpdate()` puts the reply's ADDRESSES into the cart store before it resolves

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-05 (s153, #1091 — SP-11 C-4 browser pass, WooCommerce 11.1)

## What happens

Code that awaits `wc.blocksCheckout.extensionCartUpdate()` and then reads the customer's address
from `wc/store/cart` does not read «the address as it was when I asked». It reads the address **the
server just answered with**: core has already taken the whole reply — `shipping_address` and
`billing_address` included — into the store.

That turned SP-11 C-3's guard against a late answer into a regression. The guard compared the
street line and postcode «now» with what they were when the pickup point was asked for, and rejected
the confirmation as superseded when they differed. In a store that replaces the address with the
point's (`pickup_replace_address`) the server moves the destination inside that very request, so the
guard saw its own reply as the shopper's edit — on **every first confirmation**. The dialog said
«Could not confirm your choice. Please try again.» over a point the server had kept and the button
already showed, and the billing address did not follow under «use same address for billing».

2798 jest tests were green: the test's stand-in for `extensionCartUpdate()` took the reply's
`extensions` into the store and nothing else.

## Root cause

WooCommerce 11.1, `assets/client/blocks/wc-blocks-data.js`, the `applyExtensionCartUpdate` thunk
(search `gi=`):

- the reply's addresses are kept OUT of the store only while
  `localStorage.WOOCOMMERCE_CHECKOUT_IS_CUSTOMER_DATA_DIRTY === 'true'` — the shopper has an edit
  core has not pushed yet (its push is debounced ~1 s and flushed on blur) — and the caller did not
  pass `overwriteDirtyCustomerData`;
- otherwise `receiveCart( response )` runs with the addresses in it, **before** the promise resolves.

A shopper who typed a street and then spent two seconds opening a dialog has no unsaved edit, so the
ordinary path is the second one.

## Fix

❌ Reading the store after the reply and calling any difference «the shopper edited»:

```ts
const asked = movableAddress();          // at the click
const cart = await extensionCartUpdate( … );
if ( movableAddress() !== asked ) {      // true whenever the SERVER moved the address
	throw superseded;
}
```

✅ Telling the reply's own move apart — the address as asked with the reply's fields taken into it
is not an edit (`asMovedTo()` in `src/checkout-blocks/pickup-stores.ts`):

```ts
const untouched = [ asked, addressAsMoved, asMovedTo( asked, destination ), asMovedTo( addressAsMoved, destination ) ];
if ( ! untouched.includes( movableAddress() ) ) {
	throw superseded;
}
```

✅ And in jest, a stand-in for `extensionCartUpdate()` that models core for a shopper with nothing
unsaved — it writes the reply's address into the mock store before resolving
(`coreTakesReplyAddress` in `tests/js/checkout-blocks-pickup.test.tsx`). A mock that moves only
`extensions` cannot fail on this.

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — the other place the block checkout's own request order decides behaviour
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the «dirty» flag is also what a browser probe must wait out
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — the C-3 client rules this guard belongs to
