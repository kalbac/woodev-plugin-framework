# A value equal to the one you wrote does not prove you wrote it

**Namespace:** `[shipping/pickup]`
**Discovered:** 2026-10-05 (s154, #1113 round 2 — critic REJECT of the first fix; both cases then reproduced on the rig, WooCommerce 11.1)

## What happens

«Clear the postcode the previous pickup point wrote, never one the customer typed» was first built
on a comparison: a remembered confirmation records the destination it left behind, so one whose
fingerprint still equals the current destination «proves» the postcode there is the point's. It
passed its unit tests and its rig run, and wiped the customer's postcode in two ordinary sequences:

- point P1 writes `101000` → the customer retypes `101000` → a point without a postcode → **cleared**;
- P1 writes `101000` → on another carrier's rate the customer types `654321`, then `101000` back →
  a point without a postcode → **cleared** (the first carrier's confirmation matches again).

## Root cause

**Provenance is an event, and a value comparison has no memory of events.** Equality answers «is it
the same text», never «who put it there». Worse here, the server cannot see either event at all:

- a postcode retyped to the SAME value changes nothing — core pushes no request (measured: the cart
  store holds `1`, `11`, … `117279`, no `update-customer` leaves, and core's dirty flag stays set);
- a postcode typed away and back INSIDE core's push debounce (1.5 s) reaches the server as no
  change either (measured: 0 requests carried `654321`).

So no server-side scheme built on destination values can be right, however the values are keyed.

## ❌ Wrong

```php
// «The confirmation that left this destination wrote its postcode.»
if ( $confirmation['address_key'] === $context['address_key'] ) {
	$fields['postcode'] = ''; // …also when the customer typed the same digits themselves
}
```

## ✅ Correct

Keep a record of the WRITER, apart from the value, and let any sign of another writer void it for
good (`Store_Api_Pickup::adopted_postcode()`):

- written only by the adapter, and only when a confirmation CHANGED the postcode — a point that
  shares the digits the customer had already typed claims nothing;
- voided when the server sees the destination hold another postcode: the customer route
  (`woocommerce_store_api_cart_update_customer_from_request` — the address form's push) and every
  mutation path of the adapter. Typing the first value back does not restore it;
- voided by the browser's word for what no request carries: the bundle subscribes to
  `wc/store/cart` (core writes every keystroke into it), and sends `postcode_edited: true` with the
  next selection command (`watchPostcodeEdits()`, pickup-stores.ts).

**A writer record needs an END as much as a writer** (round 3, critic REJECT of round 2). The record
lived in the session and the destination outlives a checkout: the next cart starts from the same
address, its page has seen no edit to report, and the old record still «matched» — so a point
without a postcode cleared a postcode the customer had since taken as their own. And an edit on a
page with no watcher of ours never voided it. `forget_adopted_postcode()` ends the record:

- when its checkout ends — the order placed on either checkout
  (`woocommerce_store_api_checkout_order_processed`, `woocommerce_checkout_order_processed`) and the
  cart emptied (`woocommerce_cart_emptied`; a destroyed session empties the cart, then drops the key
  with its data). All three exist in 9.9.0 and 11.1;
- when an address form posts from a page nobody watches: the classic checkout's order-review
  refresh (`woocommerce_checkout_update_order_review`), the cart shortcode's calculator
  (`woocommerce_calculated_shipping`), My Account (`woocommerce_customer_save_address`). **Ended
  outright, not compared with the posted postcode** — the classic form posts on page load and 1 s
  after ANY keystroke, so `654321` typed and `117279` typed back arrive as ONE refresh carrying
  `117279` (measured). Comparing there repeats this gotcha one level up.

Bound to events, not to the cart: the cart hash is identical for the same product bought again (the
very case), and the Store API draft order is deferred to POST since WooCommerce 10.8 and never
exists on the classic checkout.

**Trust model.** The browser's word can only KEEP a postcode. Nothing a client sends makes the
server clear one — clearing stands on the server's own record — so a forged or missing signal can at
worst leave a stale postcode in the form, where the customer sees it. A silent client (a reloaded
page, which saw no edit) leaves the decision to what the server saw.

Not covered, and accepted: the same digits retyped and the page RELOADED before the next point (the
only witness is gone, and a reload discards unsent form state anyway), and identical text pasted
over a selection (the browser fires no change at all).

## How it was proven

Unit: the customer's push is modelled by calling the hook's callback, the unseen edit by the
command's `postcode_edited` — a test that only mutates the destination fixture and puts it back
models an edit no request carried, and can pass only through the browser's word. Rig, with the
first fix as the control (`git stash`, same probe): retype-same, typed-and-back (pushed),
typed-and-back (inside the debounce), typed-and-back then reload → kept, control cleared;
untouched, and untouched then reload → cleared.

Round 3, control = the round-2 adapter: order placed → the same product again → a point without a
postcode → kept (control: cleared); on the classic page, postcode typed away and back → block
checkout → kept (control: cleared). Unit tests fire the hook through the adapter's own registration
(`has_action`), so on the old source they fail by assertion, not on a missing method.

## Related

- [core-takes-a-cart-extension-reply-s-addresses-into-the-store-before-your-code-sees-it](core-takes-a-cart-extension-reply-s-addresses-into-the-store-before-your-code-sees-it.md) — the same store, the other «is this the shopper's edit?» question
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the dirty flag after a same-value retype, met while measuring this
- [a-probe-that-finds-nothing-passes](a-probe-that-finds-nothing-passes.md) — the first fix's rig run typed a DIFFERENT postcode only, so it could not fail on these cases
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — known limits of the block checkout
