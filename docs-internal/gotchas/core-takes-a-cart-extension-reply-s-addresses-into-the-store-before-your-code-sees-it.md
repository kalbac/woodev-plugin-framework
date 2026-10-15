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

## Known limit — a SAVED edit overwritten by the late reply (#1115, operator decision pending)

The value read after the reply cannot tell «untouched» from «edited, saved by core, then overwritten by the
reply» — both read `asMovedTo( asked, destination )`. So in a `pickup_replace_address` store a late reply over a
street the shopper typed AND core already pushed is accepted: the final state is the point's address with the point
confirmed — the designed outcome of a successful choice in such a store, consistent across form, server and point; on
`main` the street is lost the same way (it rejects, but the street is already overwritten). The guard is active only
when the reply moves the address (`destination`), so stores without the move are unaffected.

❌ Do not «fix» it by watching distinct store values and restoring + pushing the last non-reply value (tried in s153,
reverted): A→B→A typing makes a real final edit look like the reply and restores the DISCARDED middle value, and the
recovery `updateCustomerData` races core's own debounced sync, leaving the server on the older address with no repair
(core has no diff against its own baseline). A real fix needs a writer signal (form setter vs reply import), not value
equality — see #1115.

## #1293: extension replies can erase the locality BEFORE core pushes it (2026-10-15)

The same import also races native setters. A fresh guest on WC 11.2.1, with forced billing delivery,
showed the chooser write both cities as `Москва`, followed 77 ms later by a payment-fee extension reply
that imported `city=''`, `state='*'` and an empty email. Core's next debounce/focusout sees that erased
form and can send it back: its private customer snapshot is not an independent form owner.

The localStorage dirty flag is **debounced 300 ms** (`It` in `wc-blocks-data.js`), while
`updateCustomerData` clears it even after a partial locality-only write (`wi`). Checking that flag or
passing `overwriteDirtyCustomerData: false` cannot close either window. The framework's immediate rate
refresh also used to save only shipping locality, leaving billing city empty on the server.

❌ Let a fee/pickup cart reply import all addresses, then restore values after the promise resolves:
React subscribers already saw the blank city and can invalidate the selection in between.

✅ `keepExtensionAddressesInForm()` removes address properties from successful replies of the server's
published framework fee/pickup namespaces **before** WooCommerce imports them, including indexed batch
responses and plain-permalink routes. Foreign namespaces and customer-update replies are untouched.
`writeDeliveryLocality()` / native setters own city/state; `adoptDestination()` explicitly adopts only
the confirmed pickup street/postcode. `refreshRates()` persists linked billing locality as well as
shipping locality, and still leaves half-typed street/postcode/email to core's own validation and push.

This supersedes the assumption in the historical fix above that our pickup replies necessarily move
addresses before resolving; the explicit destination adoption remains required. The #1115 historical
limit still describes unprotected foreign extension replies, not the now-filtered framework replies.
The regression suite is `tests/js/checkout-blocks-extension-addresses.test.ts`. Browser evidence used
no orders or option changes; the final built-bundle carrier-switch acceptance belongs to the coordinator.

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — the other place the block checkout's own request order decides behaviour
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the «dirty» flag is also what a browser probe must wait out
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — the C-3 client rules this guard belongs to
