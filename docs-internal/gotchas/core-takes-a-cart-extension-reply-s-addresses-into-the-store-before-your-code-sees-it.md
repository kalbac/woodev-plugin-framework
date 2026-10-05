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

**The first fix had the same blind spot from the other side** (critic's P1, same card). It accepted
the confirmation when the address read after the reply was «the address as asked, with the reply's
fields taken into it». But that is also exactly what the store reads when the shopper typed a new
street while the confirmation was in flight **and core had already pushed it**: nothing is unsaved,
so core puts the reply's address over the shopper's newer one, and the final value is
indistinguishable from an untouched confirmation. The late selection was endorsed and the street
the shopper typed was gone. **A value read after the reply cannot tell «untouched» from «edited,
saved, then overwritten» — only the values the address went through can.**

## Root cause

WooCommerce 11.1, `assets/client/blocks/wc-blocks-data.js`, the `applyExtensionCartUpdate` thunk
(search `gi=`):

- the reply's addresses are kept OUT of the store only while
  `localStorage.WOOCOMMERCE_CHECKOUT_IS_CUSTOMER_DATA_DIRTY === 'true'` — the shopper has an edit
  core has not pushed yet (its push is debounced 1.5 s — `en=`, 1500 — and flushed on blur) — and
  the caller did not pass `overwriteDirtyCustomerData`;
- otherwise `receiveCart( response )` runs with the addresses in it, **before** the promise resolves;
- the flag is cleared by a successful `updateCustomerData` (`wi=` → `It( false )`), and every write
  to it is itself debounced 300 ms (`It=`): «nothing unsaved» is true again as soon as core has
  pushed an edit, and still true for the first 300 ms after a fresh one.

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

❌ Reading the store after the reply and calling «asked + the reply's move» untouched — true for
an untouched confirmation AND for a pushed edit core has just overwritten:

```ts
if ( ! [ asked, asMovedTo( asked, destination ) ].includes( movableAddress() ) ) {
	throw superseded;
}
```

✅ Watching the address from the click, and judging the shopper's LAST WORD — the address as it
stood before the reply's own move, when that move is the last change (`watchAddress()` and
`sendConfirmation()` in `src/checkout-blocks/pickup-stores.ts`). Every address write is a dispatch
on `wc/store/cart` (`SET_SHIPPING_ADDRESS` per form write, `SET_CART_DATA` for a reply), so a
`subscribe()` listener sees each value before the next one replaces it:

```ts
const seen = [ movableAddress() ];                       // at the click
const stop = subscribe( () => { /* push every new value */ }, 'wc/store/cart' );
const cart = await extensionCartUpdate( … );
const before = seen[ seen.length - 2 ] ?? null;
const replaced = before !== null && now === asMovedTo( before, destination );   // core took the reply
const theirs = replaced ? before : now;                  // what the shopper last left there
if ( theirs !== asked && theirs !== addressAsMoved ) {   // not as asked, not an earlier move of ours
	if ( replaced ) {
		await restoreDestination( … );                   // put it back AND send it
	}
	throw superseded;
}
```

✅ Putting an overwritten edit back **and pushing it yourself** (`restoreDestination()` in
`wc-stores.ts`, `updateCustomerData( …, true )`). Core's address sync pushes what differs from ITS
last push (`Ji=`, the `dirtyProps` diff): an edit it already pushed, overwritten by a reply and put
back, differs in nothing, so core stays silent — the server keeps the point's address and the
confirmation bound to it, and the cart keeps showing a point for an address the form no longer has.
The push is awaited before the rejection, so a confirmation queued behind it reaches a server that
has already heard of the address.

✅ And in jest, a store stand-in that models all three core behaviours: the reply's address written
into the store before `extensionCartUpdate()` resolves (`coreTakesReplyAddress`, `lateAnswer`), a
`subscribe()` that hears address writes, and a shopper's edit made as an announced write
(`shopperTypes` in `tests/js/checkout-blocks-pickup.test.tsx`). A mock that moves only `extensions`
cannot fail on the first trap; one whose store announces nothing cannot fail on the second.

Known limit, by construction: a change that lands exactly on «the previous value with the reply's
fields taken into it» is read as core's. A shopper who edits the street and types it back to the
point's own address by hand, while re-confirming a point whose address the form already holds, is
indistinguishable from core overwriting that edit; the confirmation is rejected and the intermediate
edit put back. Both writes produce the same value through the same store.

## Related

- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — the other place the block checkout's own request order decides behaviour
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the «dirty» flag is also what a browser probe must wait out
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — the C-3 client rules this guard belongs to
