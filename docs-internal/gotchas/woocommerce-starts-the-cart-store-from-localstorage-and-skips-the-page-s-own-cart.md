# WooCommerce starts the cart store from `localStorage` and skips the page's own cart

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-05 (s153, #1091); measured and fixed 2026-10-05 (s154, #1111) — WooCommerce 11.1 on the rig, 9.9.0 read from source

## What happens

A block-checkout page can render a cart **the server does not hold**: another rate selected, a
pickup point shown as chosen, extension data from before the last order. Nothing is requested, the
network panel is empty, and the page stays that way until some later cart reply happens to arrive.

Measured sequence: pickup point chosen → reload → order placed → the same product added again →
`/checkout/` shows the old rate selected and «Chosen pickup point: …»; the server holds the default
rate and no point; Place Order is refused.

## Root cause

`wc/store/cart` persists `cartData` to `localStorage.storeApiCartData` on every reducer run. On the
next page:

- the store's **initial state** is that copy when the cookie `woocommerce_cart_hash` equals
  `localStorage.storeApiCartHash` (and `woocommerce_items_in_cart` is set);
- on the window's `load` core dispatches `finishResolution( 'getCartData' )` — so the resolver, the
  only thing that would read `/wc/store/v1/cart` (answered from the page's **preload**), never runs.

11.1: `assets/client/blocks/wc-blocks-data.js`, `Wi()` and the `load` listener. 9.9.0:
`client/blocks/assets/js/data/cart/index.ts` + `persistence-layer.ts` — the same code.

The hash is `md5( cart items . total )` (`WC_Cart::get_cart_hash()`). It does not cover the chosen
rate (when rates cost the same), the customer's address, or any `extensions` data. The cart is
emptied by an order, the cookie goes, the `localStorage` copy stays — and matches again as soon as
the same items with the same total are back.

## ❌ Wrong

```ts
// «A reload restores the point — the cart WooCommerce preloads already carries the snapshot.»
const extension = useSelect( ( select ) => select( 'wc/store/cart' ).getCartData().extensions[ NS ] );
```

True on a first visit only. With a persisted copy the preload is never applied, and
`hasFinishedResolution( 'getCartData' )` says `true` over data no server sent.

## ✅ Correct

Ask for the cart **while the bundle is evaluated** — before `load`:

```ts
select( 'wc/store/cart' ).getCartData(); // `resolveCartFromServer()` in wc-stores.ts
```

A selector call queues its resolver (`@wordpress/data`, `fulfillSelector()`: `markAsRunning` + a
`setTimeout( 0 )`), and a queued resolver runs whatever marks the selector resolved in the meantime.
Core's resolver then reads `/wc/store/v1/cart`, which the Checkout page preloads — **no request** —
and the store ends on the page's own cart. On a first visit it changes nothing.

Not chosen, and why:

- **`woocommerce_cart_hash` filter** (mix the chosen rate and the pickup snapshot into the hash) —
  no client code and no flash, but the same hash decides whether the classic checkout RESUMES a
  failed order (`WC_Checkout::create_order()`: `$order->has_cart_hash()`); a retry would create a
  second order whenever the selection changed in between.
- **`invalidateResolution( 'getCartData' )` after the fact** — flips `hasFinishedResolution` back to
  `false` under a rendered checkout, and needs a way to tell core's shortcut from a real resolution.

## What is still true after the fix

Core paints the persisted copy first. Measured on the rig: the old rate and point are on screen for
5–40 ms, ≈ 250–300 ms at 6× CPU throttle, then replaced. `load` fires before the checkout's first
render there, so `hasFinishedResolution` is briefly `true` over the persisted copy — do not use it
as «the server's cart has arrived» inside that window.

## How to reproduce on the rig without placing orders

Move the server's rate out of band — a raw `fetch` to `/cart/select-shipping-rate` with the `Nonce`
header, so the store never hears — and reload. All three rig rates are free, so the hash matches.
Without the fix 4 of 8 reloads are stale; with it 0 of 8.

## Related

- [core-takes-a-cart-extension-reply-s-addresses-into-the-store-before-your-code-sees-it](core-takes-a-cart-extension-reply-s-addresses-into-the-store-before-your-code-sees-it.md) — the other place the cart store holds something other than what you assume
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — poll, and add a control
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — known limits of the block checkout
