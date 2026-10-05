# The Checkout block scrolls to the TOP for a validation error that is not one of its own fields

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-05 (s153, #1091 — measured on a 390 px viewport, WooCommerce 11.1)

## What happens

An inner block blocks Place Order the documented way — a `hidden: true` error in
`wc/store/validation` — and renders the message next to its own control once WooCommerce reveals it.
On a desktop that looks fine. On a phone the shopper presses Place Order and **nothing visible
happens**: the page jumps to the top of the checkout, the message is a screen or two below, and
nothing at the top says anything. The button looks dead.

Measured for the pickup-point button: message at `top = 1372 px` in a 664 px viewport, no notice in
view, focus on `<body>`.

## Root cause

When a placement is refused, core reveals every validation error and then looks for a field to take
the shopper to — with a selector that only matches ITS OWN fields
(`assets/client/blocks/wc-cart-checkout-base-frontend.js`, 11.1):

```js
scrollToTop( { focusableSelector: 'input:invalid, .has-error input, .has-error select' } )
```

`scrollToTop()` focuses the first match; with no match it scrolls the checkout's top scroll point
into view — the place its **notices** are rendered. A button, a custom control or a plain message is
never a match. Core does this ~50–120 ms after the reveal, so a `scrollIntoView()` / `focus()` of
your own on the reveal is simply overridden (tried first, measured: ours at 52 ms, core's at 119 ms).

After one refused attempt the checkout stays in its «has error» state, so a NEW validation error —
the block remounting on a rate switch — is revealed at once and scrolls to the top again. That is
core's behaviour, not a bug of the block.

## Fix

❌ Fighting the scroll with a timer, or dressing a control up to match core's selector.

✅ Saying the revealed error where core has just scrolled to — a notice in the checkout's own
notice area, keyed by the error's id and withdrawn with it (`announcePoint()` / `withdrawPoint()`
in `src/checkout-blocks/pickup-stores.ts`):

```ts
dispatch( 'core/notices' ).createErrorNotice( message, { id, context: 'wc/checkout', isDismissible: false } );
// …and when the error is cleared, the rate is left or the block unmounts:
dispatch( 'core/notices' ).removeNotice( id, 'wc/checkout' );
```

`'wc/checkout'` is `noticeContexts.CHECKOUT`, the context of the container at the top of the form.
It is also where the server's refusal of the same order appears, so the two read alike. Keep the
inline message and its `aria-describedby` — the notice is in addition to it.

## Related

- [the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other](the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — the classic checkout's version of «the gate works and the shopper cannot tell why»
- [mobile-inline-min-width-and-floating-control-stacking](mobile-inline-min-width-and-floating-control-stacking.md) — the other defects only a narrow viewport shows
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — §5, «a rendered error must provide an accessible way to reach the picker»
